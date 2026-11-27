#!/usr/bin/env python3
"""Contract test: reject untrusted GitHub Actions expressions inside run: and with.script blocks.

Cubre `steps.*.outputs`, `needs.*.outputs`, `inputs.*` / `inputs['...']` / `toJSON(inputs)`,
`github.event` (incl. `github.event.*`, `github.event['...']`, `toJSON(github.event)`),
`github.head_ref`, `github.base_ref`, `github.ref_name`, expresiones con llaves internas como
`format('{0}', ...)`, y sinks de doble evaluación (`eval`, `bash/sh/zsh/dash -c`, `ssh`, `su -c`,
`xargs sh/bash`, `| sh`/`| bash`, `bash/sh <<<`, `source <(...)`/`. <(...)`, y continuaciones
multilínea con backslash) alimentados con variables `env:` (workflow, job o step) derivadas de contextos
untrusted.

Límites conocidos del detector:
- Solo inspecciona archivos bajo `.github/workflows` (`*.yml` / `*.yaml`).
- En modo fallback sin PyYAML (`yaml is None`), no cubre flow mappings (`{ run: ... }`) ni anclas YAML.
- En ambos modos (PyYAML y fallback), no cubre intérpretes externos (`python3 -c`, `node -e`, `awk`),
  cadenas entre comillas multilínea, reasignaciones indirectas de variables shell hacia sinks
  (`tmp="$INPUT_X"; eval "$tmp"`), ni formas indirectas de `steps`/`needs` como `steps['a'].outputs`
  o `toJSON(steps)`.
"""

from __future__ import annotations

import re
import sys
import unittest
from pathlib import Path

sys.dont_write_bytecode = True

try:
    import yaml
except ImportError:  # pragma: no cover - fallback for runners without PyYAML
    yaml = None


ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS_DIR = ROOT / '.github' / 'workflows'
UNTRUSTED_CONTEXT_PATTERNS = (
    r'\b(?:steps|needs)\.(?:\}(?!\})|[^}])+\.outputs\b',
    r'\binputs(?:\.|\[)|\btoJSON\s*\(\s*inputs\s*\)',
    r'\bgithub\.event\b',
    r'\bgithub\.head_ref\b',
    r'\bgithub\.base_ref\b',
    r'\bgithub\.ref_name\b',
)
STEPS_OUTPUTS_RE = re.compile(
    r'\$\{\{(?:\}(?!\})|[^}])*(?:'
    + '|'.join(UNTRUSTED_CONTEXT_PATTERNS)
    + r')(?:\}(?!\})|[^}])*\}\}',
    re.DOTALL,
)
SHELL_EVAL_SINK_PATTERNS = (
    r'(?:^|[;|&(`]|\s)eval(?:\s|$)',
    r'(?:^|[;|&(`]|\s)(?:/[^\s;|&]*?/)?(?:bash|sh|zsh|dash)\b[^#\n;|&]*\s-[a-zA-Z]*c\b',
    r'(?:^|[;|&(`]|\s)(?:/[^\s;|&]*?/)?ssh(?:\s|$)',
    r'(?:^|[;|&(`]|\s)(?:/[^\s;|&]*?/)?su\b[^#\n;|&]*\s-[a-zA-Z]*c\b',
    r'(?:^|[;|&(`]|\s)(?:/[^\s;|&]*?/)?xargs\b[^#\n;|&]*\s(?:/[^\s;|&]*?/)?(?:bash|sh|zsh|dash)\b',
    r'\|\s*(?:/[^\s;|&]*?/)?(?:bash|sh|zsh|dash)\b',
    r'(?:^|[;|&(`]|\s)(?:/[^\s;|&]*?/)?(?:bash|sh|zsh|dash)\b[^#\n;|&]*<<<',
    r'(?:^|[;|&(`]|\s)(?:source|\.)\s+<\(',
)
SHELL_EVAL_SINK_RE = re.compile('|'.join(SHELL_EVAL_SINK_PATTERNS))


def _join_shell_continuations(run_text: str) -> list[str]:
    logical: list[str] = []
    buf: list[str] = []
    for raw in run_text.splitlines():
        if not buf and raw.lstrip().startswith('#'):
            continue
        stripped_right = raw.rstrip()
        if stripped_right.endswith('\\') and not stripped_right.endswith('\\\\'):
            buf.append(stripped_right[:-1])
            continue
        if buf:
            buf.append(raw.lstrip())
            logical.append(' '.join(buf))
            buf = []
        else:
            logical.append(raw)
    if buf:
        logical.append(' '.join(buf))
    return logical


def _find_shell_eval_sink_vars(run_text: str, untrusted_env_vars: set[str]) -> list[str]:
    if not untrusted_env_vars:
        return []
    found: set[str] = set()
    for line in _join_shell_continuations(run_text):
        if line.lstrip().startswith('#'):
            continue
        if not SHELL_EVAL_SINK_RE.search(line):
            continue
        for var in untrusted_env_vars:
            if re.search(rf'\$(?:\{{{re.escape(var)}\}}|{re.escape(var)}\b)', line):
                found.add(var)
    return sorted(found)


def _collect_env_block_vars(lines: list[str], env_line_idx: int, env_indent: int) -> set[str]:
    found: set[str] = set()
    k = env_line_idx + 1
    while k < len(lines):
        el = lines[k]
        if el.strip() == '':
            k += 1
            continue
        el_indent = len(el) - len(el.lstrip(' '))
        if el_indent <= env_indent:
            break
        m_kv = re.match(r'^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:(.*)$', el)
        if m_kv and STEPS_OUTPUTS_RE.search(m_kv.group(2)):
            found.add(m_kv.group(1))
        k += 1
    return found


def _extract_untrusted_env_vars_from_lines(lines: list[str], start_idx: int, key_indent: int) -> set[str]:
    untrusted: set[str] = set()

    # 1. Workflow-level env: (indent 0)
    for k, ln in enumerate(lines):
        if re.match(r'^env\s*:\s*$', ln):
            untrusted.update(_collect_env_block_vars(lines, k, 0))

    # 2. Enclosing job-level env: (same indent as enclosing steps:)
    steps_line_idx: int | None = None
    steps_indent = -1
    for k in range(start_idx - 1, -1, -1):
        ln = lines[k]
        if ln.strip() == '':
            continue
        ind = len(ln) - len(ln.lstrip(' '))
        if ind < key_indent and re.match(r'^\s*steps\s*:\s*$', ln):
            steps_line_idx = k
            steps_indent = ind
            break
    if steps_line_idx is not None:
        job_start = steps_line_idx
        while job_start > 0:
            prev = lines[job_start - 1]
            if prev.strip() == '':
                job_start -= 1
                continue
            if len(prev) - len(prev.lstrip(' ')) < steps_indent:
                break
            job_start -= 1
        job_end = steps_line_idx + 1
        while job_end < len(lines):
            nxt = lines[job_end]
            if nxt.strip() == '':
                job_end += 1
                continue
            if len(nxt) - len(nxt.lstrip(' ')) < steps_indent:
                break
            job_end += 1
        for k in range(job_start, job_end):
            ln = lines[k]
            if len(ln) - len(ln.lstrip(' ')) == steps_indent and re.match(r'^\s*env\s*:\s*$', ln):
                untrusted.update(_collect_env_block_vars(lines, k, steps_indent))

    # 3. Current step-level env: (before or after run:)
    step_start = start_idx
    while step_start > 0:
        prev = lines[step_start - 1]
        if prev.strip() == '':
            step_start -= 1
            continue
        prev_indent = len(prev) - len(prev.lstrip(' '))
        if prev_indent < key_indent:
            if re.match(r'^\s*-\s+', prev) and prev_indent >= key_indent - 2:
                step_start -= 1
            break
        step_start -= 1

    step_end = start_idx + 1
    while step_end < len(lines):
        nxt = lines[step_end]
        if nxt.strip() == '':
            step_end += 1
            continue
        nxt_indent = len(nxt) - len(nxt.lstrip(' '))
        if nxt_indent < key_indent:
            break
        if nxt_indent == key_indent and re.match(r'^\s*-\s+', nxt):
            break
        step_end += 1

    for k in range(step_start, step_end):
        m_env = re.match(r'^(\s*(?:-\s+)?)env\s*:\s*$', lines[k])
        if m_env:
            untrusted.update(_collect_env_block_vars(lines, k, len(m_env.group(1))))
    return untrusted


def _find_with_pyyaml(workflow_text: str, workflow_name: str) -> list[str]:
    data = yaml.safe_load(workflow_text)
    if not isinstance(data, dict):
        return []

    violations: list[str] = []
    workflow_env = data.get('env') if isinstance(data.get('env'), dict) else {}
    jobs = data.get('jobs') or {}
    if not isinstance(jobs, dict):
        return []

    for job_id, job in jobs.items():
        if not isinstance(job, dict):
            continue
        job_env = job.get('env') if isinstance(job.get('env'), dict) else {}
        steps = job.get('steps') or []
        if not isinstance(steps, list):
            continue
        for idx, step in enumerate(steps):
            if not isinstance(step, dict):
                continue
            step_label = step.get('name') or f'step[{idx}]'
            step_env = step.get('env') if isinstance(step.get('env'), dict) else {}
            merged_env = {**workflow_env, **job_env, **step_env}
            untrusted_env_vars = {
                k
                for k, v in merged_env.items()
                if isinstance(k, str) and isinstance(v, str) and STEPS_OUTPUTS_RE.search(v)
            }
            run_script = step.get('run')
            if isinstance(run_script, str):
                matches = STEPS_OUTPUTS_RE.findall(run_script)
                if matches:
                    violations.append(
                        f'{workflow_name} :: job {job_id} :: {step_label!r} (run) -> {sorted(set(matches))}'
                    )
                sink_vars = _find_shell_eval_sink_vars(run_script, untrusted_env_vars)
                if sink_vars:
                    violations.append(
                        f'{workflow_name} :: job {job_id} :: {step_label!r} (run shell-eval-sink) -> {sink_vars}'
                    )
            with_cfg = step.get('with')
            if isinstance(with_cfg, dict):
                script_val = with_cfg.get('script')
                if isinstance(script_val, str):
                    matches = STEPS_OUTPUTS_RE.findall(script_val)
                    if matches:
                        violations.append(
                            f'{workflow_name} :: job {job_id} :: {step_label!r} (with.script) -> {sorted(set(matches))}'
                        )
    return violations


def _find_fallback(workflow_text: str, workflow_name: str) -> list[str]:
    violations: list[str] = []
    lines = workflow_text.splitlines()
    i = 0
    while i < len(lines):
        line = lines[i]
        m = re.match(r'^(\s*(?:-\s+)?)(run|script)\s*:(.*)$', line)
        if not m:
            i += 1
            continue
        key_indent = len(m.group(1))
        key_name = m.group(2)
        key_label = 'with.script' if key_name == 'script' else key_name
        rest = m.group(3).strip()
        if rest and not re.match(r'^[|>][0-9+-]*$', rest):
            matches = STEPS_OUTPUTS_RE.findall(rest)
            if matches:
                violations.append(
                    f'{workflow_name} :: line {i + 1} ({key_label}) -> {sorted(set(matches))}'
                )
            if key_name == 'run':
                untrusted_env_vars = _extract_untrusted_env_vars_from_lines(lines, i, key_indent)
                sink_vars = _find_shell_eval_sink_vars(rest, untrusted_env_vars)
                if sink_vars:
                    violations.append(
                        f'{workflow_name} :: line {i + 1} (run shell-eval-sink) -> {sink_vars}'
                    )
            i += 1
            continue
        block_lines: list[str] = []
        j = i + 1
        while j < len(lines):
            nxt = lines[j]
            if nxt.strip() == '':
                block_lines.append(nxt)
                j += 1
                continue
            nxt_indent = len(nxt) - len(nxt.lstrip(' '))
            if nxt_indent <= key_indent:
                break
            block_lines.append(nxt)
            j += 1
        matches = STEPS_OUTPUTS_RE.findall('\n'.join(block_lines))
        if matches:
            violations.append(
                f'{workflow_name} :: line {i + 1} ({key_label}) -> {sorted(set(matches))}'
            )
        if key_name == 'run':
            untrusted_env_vars = _extract_untrusted_env_vars_from_lines(lines, i, key_indent)
            sink_vars = _find_shell_eval_sink_vars('\n'.join(block_lines), untrusted_env_vars)
            if sink_vars:
                violations.append(
                    f'{workflow_name} :: line {i + 1} (run shell-eval-sink) -> {sink_vars}'
                )
        i = j
    return violations


def find_steps_outputs_in_run(workflow_text: str, workflow_name: str = '<memory>') -> list[str]:
    """Return violations where ${{ steps.*.outputs.* }} is interpolated inside run: or with.script."""
    if yaml is not None:
        return _find_with_pyyaml(workflow_text, workflow_name)
    return _find_fallback(workflow_text, workflow_name)


class WorkflowNoStepsOutputsInRunTest(unittest.TestCase):
    def _detectors(self):
        if yaml is not None:
            return [
                ('pyyaml', find_steps_outputs_in_run),
                ('fallback', _find_fallback),
            ]
        return [('fallback', find_steps_outputs_in_run)]

    def test_detects_unsafe_interpolation_in_run_block(self) -> None:
        unsafe_workflow = """
name: Unsafe
on: push
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad step
        run: echo "v${{ steps.ini.outputs.version }}"
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                violations = detector(unsafe_workflow, 'unsafe.yml')
                self.assertEqual(1, len(violations))
                self.assertIn('steps.ini.outputs.version', violations[0])

    def test_detects_unsafe_interpolation_in_with_script(self) -> None:
        unsafe_script_workflow = """
name: Unsafe Script
on: push
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad github-script
        uses: actions/github-script@v7
        with:
          script: |
            console.log("${{ steps.ini.outputs.name }}")
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                violations = detector(unsafe_script_workflow, 'unsafe-script.yml')
                self.assertEqual(1, len(violations))
                self.assertIn('with.script', violations[0])
                self.assertIn('steps.ini.outputs.name', violations[0])

    def test_detects_unsafe_interpolation_in_list_prefixed_run_block(self) -> None:
        list_run_workflow = """
name: List Run
on: push
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - run: |
          echo "first line safe"
          echo "v${{ steps.ini.outputs.version }}"
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                violations = detector(list_run_workflow, 'list-run.yml')
                self.assertEqual(1, len(violations))
                self.assertIn('steps.ini.outputs.version', violations[0])

    def test_detects_unsafe_interpolation_after_blank_line_in_run_block(self) -> None:
        blank_line_workflow = """
name: Blank Line Run
on: push
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Multiline with blank line
        run: |
          echo "before blank line"

          echo "v${{ steps.ini.outputs.version }}"
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                violations = detector(blank_line_workflow, 'blank-line.yml')
                self.assertEqual(1, len(violations))
                self.assertIn('steps.ini.outputs.version', violations[0])

    def test_detects_needs_outputs_with_and_without_spaces(self) -> None:
        cases = (
            ('with_spaces', '${{ needs.prepare.outputs.foo }}'),
            ('without_spaces', '${{needs.prepare.outputs.foo}}'),
        )
        for label, expr in cases:
            workflow = f"""
name: Needs Outputs
on: push
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Bad needs output
        run: echo "{expr}"
"""
            for mode, detector in self._detectors():
                with self.subTest(case=label, mode=mode):
                    violations = detector(workflow, f'needs-{label}.yml')
                    self.assertEqual(1, len(violations))
                    self.assertIn('needs.prepare.outputs.foo', violations[0])

    def test_detects_inputs_context(self) -> None:
        for label, expr in (
            ('with_spaces', '${{ inputs.x }}'),
            ('without_spaces', '${{inputs.x}}'),
        ):
            workflow = f"""
name: Inputs Context
on: workflow_dispatch
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad inputs
        run: echo "{expr}"
"""
            for mode, detector in self._detectors():
                with self.subTest(case=label, mode=mode):
                    violations = detector(workflow, f'inputs-{label}.yml')
                    self.assertEqual(1, len(violations))
                    self.assertIn('inputs.x', violations[0])

    def test_detects_github_event_context(self) -> None:
        for label, expr in (
            ('with_spaces', '${{ github.event.pull_request.title }}'),
            ('without_spaces', '${{github.event.issue.title}}'),
        ):
            workflow = f"""
name: GitHub Event Context
on: pull_request
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad github.event
        run: echo "{expr}"
"""
            for mode, detector in self._detectors():
                with self.subTest(case=label, mode=mode):
                    violations = detector(workflow, f'github-event-{label}.yml')
                    self.assertEqual(1, len(violations))
                    self.assertIn('github.event.', violations[0])

    def test_detects_github_head_ref_context(self) -> None:
        for label, expr in (
            ('with_spaces', '${{ github.head_ref }}'),
            ('without_spaces', '${{github.head_ref}}'),
        ):
            workflow = f"""
name: GitHub Head Ref
on: pull_request
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad head_ref
        run: echo "{expr}"
"""
            for mode, detector in self._detectors():
                with self.subTest(case=label, mode=mode):
                    violations = detector(workflow, f'head-ref-{label}.yml')
                    self.assertEqual(1, len(violations))
                    self.assertIn('github.head_ref', violations[0])

    def test_detects_github_base_ref_context(self) -> None:
        for label, expr in (
            ('with_spaces', '${{ github.base_ref }}'),
            ('without_spaces', '${{github.base_ref}}'),
        ):
            workflow = f"""
name: GitHub Base Ref
on: pull_request
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad base_ref
        run: echo "{expr}"
"""
            for mode, detector in self._detectors():
                with self.subTest(case=label, mode=mode):
                    violations = detector(workflow, f'base-ref-{label}.yml')
                    self.assertEqual(1, len(violations))
                    self.assertIn('github.base_ref', violations[0])

    def test_detects_github_ref_name_context(self) -> None:
        for label, expr in (
            ('with_spaces', '${{ github.ref_name }}'),
            ('without_spaces', '${{github.ref_name}}'),
        ):
            workflow = f"""
name: GitHub Ref Name
on: push
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad ref_name
        run: echo "{expr}"
"""
            for mode, detector in self._detectors():
                with self.subTest(case=label, mode=mode):
                    violations = detector(workflow, f'ref-name-{label}.yml')
                    self.assertEqual(1, len(violations))
                    self.assertIn('github.ref_name', violations[0])

    def test_detects_tojson_github_event(self) -> None:
        workflow = """
name: ToJSON Event
on: pull_request
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad toJSON(github.event)
        run: echo "${{ toJSON(github.event) }}"
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                violations = detector(workflow, 'tojson-event.yml')
                self.assertEqual(1, len(violations))
                self.assertIn('toJSON(github.event)', violations[0])

    def test_detects_bracket_indexed_github_event(self) -> None:
        workflow = """
name: Bracket Event
on: pull_request
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad bracket github.event
        run: echo "${{ github.event['pull_request']['title'] }}"
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                violations = detector(workflow, 'bracket-event.yml')
                self.assertEqual(1, len(violations))
                self.assertIn("github.event['pull_request']['title']", violations[0])

    def test_detects_bracket_indexed_inputs(self) -> None:
        workflow = """
name: Bracket Inputs
on: workflow_dispatch
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad bracket inputs
        run: echo "${{ inputs['x'] }}"
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                violations = detector(workflow, 'bracket-inputs.yml')
                self.assertEqual(1, len(violations))
                self.assertIn("inputs['x']", violations[0])

    def test_detects_format_with_braces_and_github_event(self) -> None:
        workflow = """
name: Format Event
on: pull_request
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Bad format with braces
        run: echo "${{ format('{0}', github.event.pull_request.title) }}"
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                violations = detector(workflow, 'format-event.yml')
                self.assertEqual(1, len(violations))
                self.assertIn('github.event.pull_request.title', violations[0])

    def test_rejects_untrusted_env_in_shell_eval_sinks(self) -> None:
        sinks = (
            ('eval', 'eval "echo ${INPUT_X}"'),
            ('bash_c', 'bash -c "echo ${INPUT_X}"'),
            ('abs_bash_c', '/bin/bash -c "echo ${INPUT_X}"'),
            ('sh_c', 'sh -lc "echo ${INPUT_X}"'),
            ('zsh_c', 'zsh -c "echo ${INPUT_X}"'),
            ('dash_c', 'dash -ec "echo ${INPUT_X}"'),
            ('ssh', 'ssh deploy@host "echo ${INPUT_X}"'),
            ('su_c', 'su www-data -c "echo ${INPUT_X}"'),
            ('xargs_sh', 'echo "${INPUT_X}" | xargs bash'),
            ('pipe_sh', 'echo "${INPUT_X}" | sh'),
            ('pipe_abs_bash', 'echo "${INPUT_X}" | /bin/bash'),
            ('herestring_bash', 'bash <<< "${INPUT_X}"'),
            ('source_procsub', 'source <(echo "${INPUT_X}")'),
            ('dot_procsub', '. <(echo "${INPUT_X}")'),
            ('multiline_bash_c', 'bash -c \\\n          "echo ${INPUT_X}"'),
        )
        for sink_name, cmd in sinks:
            wf = f"""
name: Sink {sink_name}
on: workflow_dispatch
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Sink step
        env:
          INPUT_X: ${{{{ inputs.x }}}}
        run: |
          {cmd}
"""
            for mode, detector in self._detectors():
                with self.subTest(sink=sink_name, mode=mode):
                    violations = detector(wf, f'sink-{sink_name}.yml')
                    self.assertEqual(1, len(violations))
                    self.assertIn('shell-eval-sink', violations[0])

        scoped_workflows = (
            (
                'workflow_env',
                """name: Workflow Env Sink
on: workflow_dispatch
env:
  WF_X: ${{ inputs.x }}
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Sink workflow env
        run: |
          eval "echo $WF_X"
""",
            ),
            (
                'job_env',
                """name: Job Env Sink
on: workflow_dispatch
jobs:
  build:
    runs-on: ubuntu-latest
    env:
      JOB_X: ${{ inputs.y }}
    steps:
      - name: Sink job env
        run: |
          eval "echo $JOB_X"
""",
            ),
        )
        for scope_name, scoped_wf in scoped_workflows:
            for mode, detector in self._detectors():
                with self.subTest(scope=scope_name, mode=mode):
                    violations = detector(scoped_wf, f'{scope_name}.yml')
                    self.assertEqual(1, len(violations))
                    self.assertIn('shell-eval-sink', violations[0])

    def test_allows_steps_outputs_passed_via_env(self) -> None:
        safe_workflow = """
name: Safe
on: push
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Safe step
        env:
          PLUGIN_VERSION: ${{ steps.ini.outputs.version }}
        run: echo "v${PLUGIN_VERSION}"
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                self.assertEqual([], detector(safe_workflow, 'safe.yml'))

    def test_allows_env_after_run_block_without_false_positive(self) -> None:
        safe_env_after_run = """
name: Safe Env After Run
on: push
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Safe step with env after run
        run: |
          echo "v${PLUGIN_VERSION}"
        env:
          PLUGIN_VERSION: ${{ steps.ini.outputs.version }}
"""
        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                self.assertEqual([], detector(safe_env_after_run, 'safe-env-after-run.yml'))

    def test_all_repository_workflows_forbid_steps_outputs_in_run(self) -> None:
        workflow_files = sorted(
            list(WORKFLOWS_DIR.glob('*.yml')) + list(WORKFLOWS_DIR.glob('*.yaml'))
        )
        self.assertTrue(workflow_files, f'No workflow files found in {WORKFLOWS_DIR}')

        for mode, detector in self._detectors():
            with self.subTest(mode=mode):
                all_violations: list[str] = []
                for wf_path in workflow_files:
                    all_violations.extend(
                        detector(wf_path.read_text(encoding='utf-8'), wf_path.name)
                    )

                self.assertEqual(
                    [],
                    all_violations,
                    f'[{mode}] Workflows must pass ${{{{ (steps|needs).*.outputs.* }}}} via env: '
                    'instead of interpolating in run:/with.script:\n'
                    + '\n'.join(all_violations),
                )


if __name__ == '__main__':
    unittest.main()
