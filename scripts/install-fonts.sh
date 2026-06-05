#!/usr/bin/env bash
#
# install-fonts.sh — descarga un conjunto curado de Google Fonts (OFL/Apache)
# para BeplyPDFStudio y las instala OFFLINE dentro de la imagen del contenedor.
#
# Destino TTF:       /usr/local/share/fonts/beplypdf/
# Destino licencias: /usr/local/share/fonts/beplypdf/licenses/<familia>/
#
# Las fuentes quedan disponibles para:
#   (a) rsvg-convert / fontconfig -> por NOMBRE de familia (tras fc-cache).
#   (b) R&OS pdf-php (Cezpdf)      -> por RUTA al fichero TTF (ver Assets/Fonts/fonts.json).
#
# Diseño tolerante a fallos: si una familia/fichero falla la descarga, se registra
# un WARNING y se continúa; el build NO se rompe por una fuente puntual.
#
# Fuente: repositorio público github.com/google/fonts (rama main).
# Las familias variable-font se descargan como un único fichero "-Regular.ttf"
# que contiene todo el rango de pesos; el manifest apunta regular y bold al mismo
# fichero (fontconfig instancia el peso; rospdf embebe el fichero).
#
set -u

FONT_DIR="/usr/local/share/fonts/beplypdf"
LIC_DIR="${FONT_DIR}/licenses"
RAW="https://raw.githubusercontent.com/google/fonts/main"

mkdir -p "${FONT_DIR}" "${LIC_DIR}"

ok=0
fail=0
failed_list=""

download() {
    # $1 url  $2 destino-absoluto
    local url="$1" dest="$2"
    local tmp="${dest}.part"
    # hasta 3 reintentos, sigue redirecciones, falla en HTTP>=400
    if curl -fsSL --retry 3 --retry-delay 2 -o "${tmp}" "${url}"; then
        # validación mínima: fichero no vacío y > 1KB (un TTF real nunca es tan pequeño)
        if [ -s "${tmp}" ] && [ "$(stat -c%s "${tmp}" 2>/dev/null || echo 0)" -gt 1024 ]; then
            mv -f "${tmp}" "${dest}"
            return 0
        fi
    fi
    rm -f "${tmp}"
    return 1
}

# Cache de licencias ya descargadas por directorio de familia
declare -A LIC_DONE

fetch_license() {
    # $1 = directorio de la familia en el repo (p.ej. ofl/roboto)
    local famdir="$1"
    [ -z "${famdir}" ] && return 0
    [ -n "${LIC_DONE[$famdir]:-}" ] && return 0
    LIC_DONE[$famdir]=1
    local famname
    famname="$(basename "${famdir}")"
    local out="${LIC_DIR}/${famname}"
    mkdir -p "${out}"
    local got=0
    for lf in OFL.txt LICENSE.txt LICENSE; do
        if curl -fsSL --retry 2 -o "${out}/${lf}" "${RAW}/${famdir}/${lf}" 2>/dev/null; then
            if [ -s "${out}/${lf}" ]; then got=1; break; fi
            rm -f "${out}/${lf}"
        fi
    done
    [ "${got}" -eq 0 ] && echo "  [lic] WARNING: sin licencia descargable para ${famdir}"
    return 0
}

echo "== BeplyPDFStudio: instalando Google Fonts en ${FONT_DIR} =="

# Tabla de fuentes: URL|FICHERO_DESTINO|DIR_LICENCIA
while IFS='|' read -r url dest famdir; do
    [ -z "${url}" ] && continue
    case "${url}" in \#*) continue;; esac
    if download "${url}" "${FONT_DIR}/${dest}"; then
        ok=$((ok+1))
        fetch_license "${famdir}"
    else
        fail=$((fail+1))
        failed_list="${failed_list}\n  - ${dest}  (${url})"
        echo "  [font] WARNING: no se pudo descargar ${dest} desde ${url}"
    fi
done <<'FONTDATA'
https://raw.githubusercontent.com/google/fonts/main/ofl/roboto/Roboto%5Bwdth%2Cwght%5D.ttf|Roboto-Regular.ttf|ofl/roboto
https://raw.githubusercontent.com/google/fonts/main/ofl/roboto/Roboto-Italic%5Bwdth%2Cwght%5D.ttf|Roboto-Italic.ttf|ofl/roboto
https://raw.githubusercontent.com/google/fonts/main/ofl/opensans/OpenSans%5Bwdth%2Cwght%5D.ttf|OpenSans-Regular.ttf|ofl/opensans
https://raw.githubusercontent.com/google/fonts/main/ofl/opensans/OpenSans-Italic%5Bwdth%2Cwght%5D.ttf|OpenSans-Italic.ttf|ofl/opensans
https://raw.githubusercontent.com/google/fonts/main/ofl/lato/Lato-Regular.ttf|Lato-Regular.ttf|ofl/lato
https://raw.githubusercontent.com/google/fonts/main/ofl/lato/Lato-Bold.ttf|Lato-Bold.ttf|ofl/lato
https://raw.githubusercontent.com/google/fonts/main/ofl/lato/Lato-Italic.ttf|Lato-Italic.ttf|ofl/lato
https://raw.githubusercontent.com/google/fonts/main/ofl/lato/Lato-BoldItalic.ttf|Lato-BoldItalic.ttf|ofl/lato
https://raw.githubusercontent.com/google/fonts/main/ofl/montserrat/Montserrat%5Bwght%5D.ttf|Montserrat-Regular.ttf|ofl/montserrat
https://raw.githubusercontent.com/google/fonts/main/ofl/montserrat/Montserrat-Italic%5Bwght%5D.ttf|Montserrat-Italic.ttf|ofl/montserrat
https://raw.githubusercontent.com/google/fonts/main/ofl/poppins/Poppins-Regular.ttf|Poppins-Regular.ttf|ofl/poppins
https://raw.githubusercontent.com/google/fonts/main/ofl/poppins/Poppins-Bold.ttf|Poppins-Bold.ttf|ofl/poppins
https://raw.githubusercontent.com/google/fonts/main/ofl/poppins/Poppins-Italic.ttf|Poppins-Italic.ttf|ofl/poppins
https://raw.githubusercontent.com/google/fonts/main/ofl/poppins/Poppins-BoldItalic.ttf|Poppins-BoldItalic.ttf|ofl/poppins
https://raw.githubusercontent.com/google/fonts/main/ofl/inter/Inter%5Bopsz%2Cwght%5D.ttf|Inter-Regular.ttf|ofl/inter
https://raw.githubusercontent.com/google/fonts/main/ofl/inter/Inter-Italic%5Bopsz%2Cwght%5D.ttf|Inter-Italic.ttf|ofl/inter
https://raw.githubusercontent.com/google/fonts/main/ofl/nunito/Nunito%5Bwght%5D.ttf|Nunito-Regular.ttf|ofl/nunito
https://raw.githubusercontent.com/google/fonts/main/ofl/nunito/Nunito-Italic%5Bwght%5D.ttf|Nunito-Italic.ttf|ofl/nunito
https://raw.githubusercontent.com/google/fonts/main/ofl/nunitosans/NunitoSans%5BYTLC%2Copsz%2Cwdth%2Cwght%5D.ttf|NunitoSans-Regular.ttf|ofl/nunitosans
https://raw.githubusercontent.com/google/fonts/main/ofl/nunitosans/NunitoSans-Italic%5BYTLC%2Copsz%2Cwdth%2Cwght%5D.ttf|NunitoSans-Italic.ttf|ofl/nunitosans
https://raw.githubusercontent.com/google/fonts/main/ofl/worksans/WorkSans%5Bwght%5D.ttf|WorkSans-Regular.ttf|ofl/worksans
https://raw.githubusercontent.com/google/fonts/main/ofl/worksans/WorkSans-Italic%5Bwght%5D.ttf|WorkSans-Italic.ttf|ofl/worksans
https://raw.githubusercontent.com/google/fonts/main/ofl/sourcesans3/SourceSans3%5Bwght%5D.ttf|SourceSans3-Regular.ttf|ofl/sourcesans3
https://raw.githubusercontent.com/google/fonts/main/ofl/sourcesans3/SourceSans3-Italic%5Bwght%5D.ttf|SourceSans3-Italic.ttf|ofl/sourcesans3
https://raw.githubusercontent.com/google/fonts/main/ofl/raleway/Raleway%5Bwght%5D.ttf|Raleway-Regular.ttf|ofl/raleway
https://raw.githubusercontent.com/google/fonts/main/ofl/raleway/Raleway-Italic%5Bwght%5D.ttf|Raleway-Italic.ttf|ofl/raleway
https://raw.githubusercontent.com/google/fonts/main/ofl/mulish/Mulish%5Bwght%5D.ttf|Mulish-Regular.ttf|ofl/mulish
https://raw.githubusercontent.com/google/fonts/main/ofl/mulish/Mulish-Italic%5Bwght%5D.ttf|Mulish-Italic.ttf|ofl/mulish
https://raw.githubusercontent.com/google/fonts/main/ofl/rubik/Rubik%5Bwght%5D.ttf|Rubik-Regular.ttf|ofl/rubik
https://raw.githubusercontent.com/google/fonts/main/ofl/rubik/Rubik-Italic%5Bwght%5D.ttf|Rubik-Italic.ttf|ofl/rubik
https://raw.githubusercontent.com/google/fonts/main/ofl/karla/Karla%5Bwght%5D.ttf|Karla-Regular.ttf|ofl/karla
https://raw.githubusercontent.com/google/fonts/main/ofl/karla/Karla-Italic%5Bwght%5D.ttf|Karla-Italic.ttf|ofl/karla
https://raw.githubusercontent.com/google/fonts/main/ofl/manrope/Manrope%5Bwght%5D.ttf|Manrope-Regular.ttf|ofl/manrope
https://raw.githubusercontent.com/google/fonts/main/ofl/jost/Jost%5Bwght%5D.ttf|Jost-Regular.ttf|ofl/jost
https://raw.githubusercontent.com/google/fonts/main/ofl/jost/Jost-Italic%5Bwght%5D.ttf|Jost-Italic.ttf|ofl/jost
https://raw.githubusercontent.com/google/fonts/main/ofl/barlow/Barlow-Regular.ttf|Barlow-Regular.ttf|ofl/barlow
https://raw.githubusercontent.com/google/fonts/main/ofl/barlow/Barlow-Bold.ttf|Barlow-Bold.ttf|ofl/barlow
https://raw.githubusercontent.com/google/fonts/main/ofl/barlow/Barlow-Italic.ttf|Barlow-Italic.ttf|ofl/barlow
https://raw.githubusercontent.com/google/fonts/main/ofl/barlow/Barlow-BoldItalic.ttf|Barlow-BoldItalic.ttf|ofl/barlow
https://raw.githubusercontent.com/google/fonts/main/ofl/hind/Hind-Regular.ttf|Hind-Regular.ttf|ofl/hind
https://raw.githubusercontent.com/google/fonts/main/ofl/hind/Hind-Bold.ttf|Hind-Bold.ttf|ofl/hind
https://raw.githubusercontent.com/google/fonts/main/ofl/titilliumweb/TitilliumWeb-Regular.ttf|TitilliumWeb-Regular.ttf|ofl/titilliumweb
https://raw.githubusercontent.com/google/fonts/main/ofl/titilliumweb/TitilliumWeb-Bold.ttf|TitilliumWeb-Bold.ttf|ofl/titilliumweb
https://raw.githubusercontent.com/google/fonts/main/ofl/titilliumweb/TitilliumWeb-Italic.ttf|TitilliumWeb-Italic.ttf|ofl/titilliumweb
https://raw.githubusercontent.com/google/fonts/main/ofl/titilliumweb/TitilliumWeb-BoldItalic.ttf|TitilliumWeb-BoldItalic.ttf|ofl/titilliumweb
https://raw.githubusercontent.com/google/fonts/main/ofl/cabin/Cabin%5Bwdth%2Cwght%5D.ttf|Cabin-Regular.ttf|ofl/cabin
https://raw.githubusercontent.com/google/fonts/main/ofl/cabin/Cabin-Italic%5Bwdth%2Cwght%5D.ttf|Cabin-Italic.ttf|ofl/cabin
https://raw.githubusercontent.com/google/fonts/main/ofl/firasans/FiraSans-Regular.ttf|FiraSans-Regular.ttf|ofl/firasans
https://raw.githubusercontent.com/google/fonts/main/ofl/firasans/FiraSans-Bold.ttf|FiraSans-Bold.ttf|ofl/firasans
https://raw.githubusercontent.com/google/fonts/main/ofl/firasans/FiraSans-Italic.ttf|FiraSans-Italic.ttf|ofl/firasans
https://raw.githubusercontent.com/google/fonts/main/ofl/firasans/FiraSans-BoldItalic.ttf|FiraSans-BoldItalic.ttf|ofl/firasans
https://raw.githubusercontent.com/google/fonts/main/ofl/ptsans/PT_Sans-Web-Regular.ttf|PTSans-Regular.ttf|ofl/ptsans
https://raw.githubusercontent.com/google/fonts/main/ofl/ptsans/PT_Sans-Web-Bold.ttf|PTSans-Bold.ttf|ofl/ptsans
https://raw.githubusercontent.com/google/fonts/main/ofl/ptsans/PT_Sans-Web-Italic.ttf|PTSans-Italic.ttf|ofl/ptsans
https://raw.githubusercontent.com/google/fonts/main/ofl/ptsans/PT_Sans-Web-BoldItalic.ttf|PTSans-BoldItalic.ttf|ofl/ptsans
https://raw.githubusercontent.com/google/fonts/main/ofl/notosans/NotoSans%5Bwdth%2Cwght%5D.ttf|NotoSans-Regular.ttf|ofl/notosans
https://raw.githubusercontent.com/google/fonts/main/ofl/notosans/NotoSans-Italic%5Bwdth%2Cwght%5D.ttf|NotoSans-Italic.ttf|ofl/notosans
https://raw.githubusercontent.com/google/fonts/main/ofl/dmsans/DMSans%5Bopsz%2Cwght%5D.ttf|DMSans-Regular.ttf|ofl/dmsans
https://raw.githubusercontent.com/google/fonts/main/ofl/dmsans/DMSans-Italic%5Bopsz%2Cwght%5D.ttf|DMSans-Italic.ttf|ofl/dmsans
https://raw.githubusercontent.com/google/fonts/main/ofl/archivo/Archivo%5Bwdth%2Cwght%5D.ttf|Archivo-Regular.ttf|ofl/archivo
https://raw.githubusercontent.com/google/fonts/main/ofl/archivo/Archivo-Italic%5Bwdth%2Cwght%5D.ttf|Archivo-Italic.ttf|ofl/archivo
https://raw.githubusercontent.com/google/fonts/main/ofl/publicsans/PublicSans%5Bwght%5D.ttf|PublicSans-Regular.ttf|ofl/publicsans
https://raw.githubusercontent.com/google/fonts/main/ofl/publicsans/PublicSans-Italic%5Bwght%5D.ttf|PublicSans-Italic.ttf|ofl/publicsans
https://raw.githubusercontent.com/google/fonts/main/ofl/figtree/Figtree%5Bwght%5D.ttf|Figtree-Regular.ttf|ofl/figtree
https://raw.githubusercontent.com/google/fonts/main/ofl/figtree/Figtree-Italic%5Bwght%5D.ttf|Figtree-Italic.ttf|ofl/figtree
https://raw.githubusercontent.com/google/fonts/main/ofl/librefranklin/LibreFranklin%5Bwght%5D.ttf|LibreFranklin-Regular.ttf|ofl/librefranklin
https://raw.githubusercontent.com/google/fonts/main/ofl/librefranklin/LibreFranklin-Italic%5Bwght%5D.ttf|LibreFranklin-Italic.ttf|ofl/librefranklin
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexsans/IBMPlexSans%5Bwdth%2Cwght%5D.ttf|IBMPlexSans-Regular.ttf|ofl/ibmplexsans
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexsans/IBMPlexSans-Italic%5Bwdth%2Cwght%5D.ttf|IBMPlexSans-Italic.ttf|ofl/ibmplexsans
https://raw.githubusercontent.com/google/fonts/main/ofl/heebo/Heebo%5Bwght%5D.ttf|Heebo-Regular.ttf|ofl/heebo
https://raw.githubusercontent.com/google/fonts/main/ofl/assistant/Assistant%5Bwght%5D.ttf|Assistant-Regular.ttf|ofl/assistant
https://raw.githubusercontent.com/google/fonts/main/ofl/quicksand/Quicksand%5Bwght%5D.ttf|Quicksand-Regular.ttf|ofl/quicksand
https://raw.githubusercontent.com/google/fonts/main/ofl/comfortaa/Comfortaa%5Bwght%5D.ttf|Comfortaa-Regular.ttf|ofl/comfortaa
https://raw.githubusercontent.com/google/fonts/main/ofl/merriweather/Merriweather%5Bopsz%2Cwdth%2Cwght%5D.ttf|Merriweather-Regular.ttf|ofl/merriweather
https://raw.githubusercontent.com/google/fonts/main/ofl/merriweather/Merriweather-Italic%5Bopsz%2Cwdth%2Cwght%5D.ttf|Merriweather-Italic.ttf|ofl/merriweather
https://raw.githubusercontent.com/google/fonts/main/ofl/playfairdisplay/PlayfairDisplay%5Bwght%5D.ttf|PlayfairDisplay-Regular.ttf|ofl/playfairdisplay
https://raw.githubusercontent.com/google/fonts/main/ofl/playfairdisplay/PlayfairDisplay-Italic%5Bwght%5D.ttf|PlayfairDisplay-Italic.ttf|ofl/playfairdisplay
https://raw.githubusercontent.com/google/fonts/main/ofl/lora/Lora%5Bwght%5D.ttf|Lora-Regular.ttf|ofl/lora
https://raw.githubusercontent.com/google/fonts/main/ofl/lora/Lora-Italic%5Bwght%5D.ttf|Lora-Italic.ttf|ofl/lora
https://raw.githubusercontent.com/google/fonts/main/ofl/ptserif/PT_Serif-Web-Regular.ttf|PTSerif-Regular.ttf|ofl/ptserif
https://raw.githubusercontent.com/google/fonts/main/ofl/ptserif/PT_Serif-Web-Bold.ttf|PTSerif-Bold.ttf|ofl/ptserif
https://raw.githubusercontent.com/google/fonts/main/ofl/ptserif/PT_Serif-Web-Italic.ttf|PTSerif-Italic.ttf|ofl/ptserif
https://raw.githubusercontent.com/google/fonts/main/ofl/ptserif/PT_Serif-Web-BoldItalic.ttf|PTSerif-BoldItalic.ttf|ofl/ptserif
https://raw.githubusercontent.com/google/fonts/main/apache/robotoslab/RobotoSlab%5Bwght%5D.ttf|RobotoSlab-Regular.ttf|apache/robotoslab
https://raw.githubusercontent.com/google/fonts/main/ofl/sourceserif4/SourceSerif4%5Bopsz%2Cwght%5D.ttf|SourceSerif4-Regular.ttf|ofl/sourceserif4
https://raw.githubusercontent.com/google/fonts/main/ofl/sourceserif4/SourceSerif4-Italic%5Bopsz%2Cwght%5D.ttf|SourceSerif4-Italic.ttf|ofl/sourceserif4
https://raw.githubusercontent.com/google/fonts/main/ofl/ebgaramond/EBGaramond%5Bwght%5D.ttf|EBGaramond-Regular.ttf|ofl/ebgaramond
https://raw.githubusercontent.com/google/fonts/main/ofl/ebgaramond/EBGaramond-Italic%5Bwght%5D.ttf|EBGaramond-Italic.ttf|ofl/ebgaramond
https://raw.githubusercontent.com/google/fonts/main/ofl/librebaskerville/LibreBaskerville%5Bwght%5D.ttf|LibreBaskerville-Regular.ttf|ofl/librebaskerville
https://raw.githubusercontent.com/google/fonts/main/ofl/librebaskerville/LibreBaskerville-Italic%5Bwght%5D.ttf|LibreBaskerville-Italic.ttf|ofl/librebaskerville
https://raw.githubusercontent.com/google/fonts/main/ofl/cormorantgaramond/CormorantGaramond%5Bwght%5D.ttf|CormorantGaramond-Regular.ttf|ofl/cormorantgaramond
https://raw.githubusercontent.com/google/fonts/main/ofl/cormorantgaramond/CormorantGaramond-Italic%5Bwght%5D.ttf|CormorantGaramond-Italic.ttf|ofl/cormorantgaramond
https://raw.githubusercontent.com/google/fonts/main/ofl/crimsontext/CrimsonText-Regular.ttf|CrimsonText-Regular.ttf|ofl/crimsontext
https://raw.githubusercontent.com/google/fonts/main/ofl/crimsontext/CrimsonText-Bold.ttf|CrimsonText-Bold.ttf|ofl/crimsontext
https://raw.githubusercontent.com/google/fonts/main/ofl/crimsontext/CrimsonText-Italic.ttf|CrimsonText-Italic.ttf|ofl/crimsontext
https://raw.githubusercontent.com/google/fonts/main/ofl/crimsontext/CrimsonText-BoldItalic.ttf|CrimsonText-BoldItalic.ttf|ofl/crimsontext
https://raw.githubusercontent.com/google/fonts/main/ofl/crimsonpro/CrimsonPro%5Bwght%5D.ttf|CrimsonPro-Regular.ttf|ofl/crimsonpro
https://raw.githubusercontent.com/google/fonts/main/ofl/crimsonpro/CrimsonPro-Italic%5Bwght%5D.ttf|CrimsonPro-Italic.ttf|ofl/crimsonpro
https://raw.githubusercontent.com/google/fonts/main/ofl/spectral/Spectral-Regular.ttf|Spectral-Regular.ttf|ofl/spectral
https://raw.githubusercontent.com/google/fonts/main/ofl/spectral/Spectral-Bold.ttf|Spectral-Bold.ttf|ofl/spectral
https://raw.githubusercontent.com/google/fonts/main/ofl/spectral/Spectral-Italic.ttf|Spectral-Italic.ttf|ofl/spectral
https://raw.githubusercontent.com/google/fonts/main/ofl/spectral/Spectral-BoldItalic.ttf|Spectral-BoldItalic.ttf|ofl/spectral
https://raw.githubusercontent.com/google/fonts/main/ofl/bitter/Bitter%5Bwght%5D.ttf|Bitter-Regular.ttf|ofl/bitter
https://raw.githubusercontent.com/google/fonts/main/ofl/bitter/Bitter-Italic%5Bwght%5D.ttf|Bitter-Italic.ttf|ofl/bitter
https://raw.githubusercontent.com/google/fonts/main/ofl/cardo/Cardo-Regular.ttf|Cardo-Regular.ttf|ofl/cardo
https://raw.githubusercontent.com/google/fonts/main/ofl/cardo/Cardo-Bold.ttf|Cardo-Bold.ttf|ofl/cardo
https://raw.githubusercontent.com/google/fonts/main/ofl/cardo/Cardo-Italic.ttf|Cardo-Italic.ttf|ofl/cardo
https://raw.githubusercontent.com/google/fonts/main/ofl/alegreya/Alegreya%5Bwght%5D.ttf|Alegreya-Regular.ttf|ofl/alegreya
https://raw.githubusercontent.com/google/fonts/main/ofl/alegreya/Alegreya-Italic%5Bwght%5D.ttf|Alegreya-Italic.ttf|ofl/alegreya
https://raw.githubusercontent.com/google/fonts/main/ofl/notoserif/NotoSerif%5Bwdth%2Cwght%5D.ttf|NotoSerif-Regular.ttf|ofl/notoserif
https://raw.githubusercontent.com/google/fonts/main/ofl/notoserif/NotoSerif-Italic%5Bwdth%2Cwght%5D.ttf|NotoSerif-Italic.ttf|ofl/notoserif
https://raw.githubusercontent.com/google/fonts/main/ofl/domine/Domine%5Bwght%5D.ttf|Domine-Regular.ttf|ofl/domine
https://raw.githubusercontent.com/google/fonts/main/ofl/zillaslab/ZillaSlab-Regular.ttf|ZillaSlab-Regular.ttf|ofl/zillaslab
https://raw.githubusercontent.com/google/fonts/main/ofl/zillaslab/ZillaSlab-Bold.ttf|ZillaSlab-Bold.ttf|ofl/zillaslab
https://raw.githubusercontent.com/google/fonts/main/ofl/zillaslab/ZillaSlab-Italic.ttf|ZillaSlab-Italic.ttf|ofl/zillaslab
https://raw.githubusercontent.com/google/fonts/main/ofl/zillaslab/ZillaSlab-BoldItalic.ttf|ZillaSlab-BoldItalic.ttf|ofl/zillaslab
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexserif/IBMPlexSerif-Regular.ttf|IBMPlexSerif-Regular.ttf|ofl/ibmplexserif
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexserif/IBMPlexSerif-Bold.ttf|IBMPlexSerif-Bold.ttf|ofl/ibmplexserif
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexserif/IBMPlexSerif-Italic.ttf|IBMPlexSerif-Italic.ttf|ofl/ibmplexserif
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexserif/IBMPlexSerif-BoldItalic.ttf|IBMPlexSerif-BoldItalic.ttf|ofl/ibmplexserif
https://raw.githubusercontent.com/google/fonts/main/ofl/robotomono/RobotoMono%5Bwght%5D.ttf|RobotoMono-Regular.ttf|ofl/robotomono
https://raw.githubusercontent.com/google/fonts/main/ofl/robotomono/RobotoMono-Italic%5Bwght%5D.ttf|RobotoMono-Italic.ttf|ofl/robotomono
https://raw.githubusercontent.com/google/fonts/main/ofl/jetbrainsmono/JetBrainsMono%5Bwght%5D.ttf|JetBrainsMono-Regular.ttf|ofl/jetbrainsmono
https://raw.githubusercontent.com/google/fonts/main/ofl/jetbrainsmono/JetBrainsMono-Italic%5Bwght%5D.ttf|JetBrainsMono-Italic.ttf|ofl/jetbrainsmono
https://raw.githubusercontent.com/google/fonts/main/ofl/sourcecodepro/SourceCodePro%5Bwght%5D.ttf|SourceCodePro-Regular.ttf|ofl/sourcecodepro
https://raw.githubusercontent.com/google/fonts/main/ofl/sourcecodepro/SourceCodePro-Italic%5Bwght%5D.ttf|SourceCodePro-Italic.ttf|ofl/sourcecodepro
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexmono/IBMPlexMono-Regular.ttf|IBMPlexMono-Regular.ttf|ofl/ibmplexmono
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexmono/IBMPlexMono-Bold.ttf|IBMPlexMono-Bold.ttf|ofl/ibmplexmono
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexmono/IBMPlexMono-Italic.ttf|IBMPlexMono-Italic.ttf|ofl/ibmplexmono
https://raw.githubusercontent.com/google/fonts/main/ofl/ibmplexmono/IBMPlexMono-BoldItalic.ttf|IBMPlexMono-BoldItalic.ttf|ofl/ibmplexmono
https://raw.githubusercontent.com/google/fonts/main/ofl/firacode/FiraCode%5Bwght%5D.ttf|FiraCode-Regular.ttf|ofl/firacode
https://raw.githubusercontent.com/google/fonts/main/ofl/spacemono/SpaceMono-Regular.ttf|SpaceMono-Regular.ttf|ofl/spacemono
https://raw.githubusercontent.com/google/fonts/main/ofl/spacemono/SpaceMono-Bold.ttf|SpaceMono-Bold.ttf|ofl/spacemono
https://raw.githubusercontent.com/google/fonts/main/ofl/spacemono/SpaceMono-Italic.ttf|SpaceMono-Italic.ttf|ofl/spacemono
https://raw.githubusercontent.com/google/fonts/main/ofl/spacemono/SpaceMono-BoldItalic.ttf|SpaceMono-BoldItalic.ttf|ofl/spacemono
https://raw.githubusercontent.com/google/fonts/main/ofl/inconsolata/static/Inconsolata-Regular.ttf|Inconsolata-Regular.ttf|ofl/inconsolata
https://raw.githubusercontent.com/google/fonts/main/ofl/inconsolata/static/Inconsolata-Bold.ttf|Inconsolata-Bold.ttf|ofl/inconsolata
https://raw.githubusercontent.com/google/fonts/main/ofl/oswald/Oswald%5Bwght%5D.ttf|Oswald-Regular.ttf|ofl/oswald
https://raw.githubusercontent.com/google/fonts/main/ofl/bebasneue/BebasNeue-Regular.ttf|BebasNeue-Regular.ttf|ofl/bebasneue
https://raw.githubusercontent.com/google/fonts/main/ofl/anton/Anton-Regular.ttf|Anton-Regular.ttf|ofl/anton
https://raw.githubusercontent.com/google/fonts/main/ofl/archivoblack/ArchivoBlack-Regular.ttf|ArchivoBlack-Regular.ttf|ofl/archivoblack
https://raw.githubusercontent.com/google/fonts/main/ofl/abrilfatface/AbrilFatface-Regular.ttf|AbrilFatface-Regular.ttf|ofl/abrilfatface
https://raw.githubusercontent.com/google/fonts/main/ofl/pacifico/Pacifico-Regular.ttf|Pacifico-Regular.ttf|ofl/pacifico
https://raw.githubusercontent.com/google/fonts/main/ofl/lobster/Lobster-Regular.ttf|Lobster-Regular.ttf|ofl/lobster
https://raw.githubusercontent.com/google/fonts/main/ofl/righteous/Righteous-Regular.ttf|Righteous-Regular.ttf|ofl/righteous
https://raw.githubusercontent.com/google/fonts/main/ofl/teko/Teko%5Bwght%5D.ttf|Teko-Regular.ttf|ofl/teko
https://raw.githubusercontent.com/google/fonts/main/ofl/josefinsans/JosefinSans%5Bwght%5D.ttf|JosefinSans-Regular.ttf|ofl/josefinsans
https://raw.githubusercontent.com/google/fonts/main/ofl/josefinsans/JosefinSans-Italic%5Bwght%5D.ttf|JosefinSans-Italic.ttf|ofl/josefinsans
https://raw.githubusercontent.com/google/fonts/main/ofl/cinzel/Cinzel%5Bwght%5D.ttf|Cinzel-Regular.ttf|ofl/cinzel
https://raw.githubusercontent.com/google/fonts/main/ofl/yesevaone/YesevaOne-Regular.ttf|YesevaOne-Regular.ttf|ofl/yesevaone
FONTDATA

echo ""
echo "== Resumen descargas: OK=${ok}  FALLOS=${fail} =="
if [ "${fail}" -gt 0 ]; then
    echo -e "Fuentes que fallaron:${failed_list}"
fi

# Fuentes adicionales EMPAQUETADAS en el propio plugin (Assets/Fonts/*.ttf), p.ej.
# Raleway-Bold.ttf —Google publica Raleway solo como variable, sin bold estático— necesaria
# para que rospdf disponga de negrita real. Se copian tal cual al FONT_DIR (carga por ruta).
BUNDLED_FONTS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/Assets/Fonts"
if [ -d "${BUNDLED_FONTS}" ]; then
    for ttf in "${BUNDLED_FONTS}"/*.ttf; do
        [ -e "${ttf}" ] || continue
        cp -f "${ttf}" "${FONT_DIR}/" && echo "== fuente empaquetada: $(basename "${ttf}") =="
    done
fi

# Raleway se descarga como fuente variable. WeasyPrint/fontconfig la entiende, pero R&OS/Cezpdf
# no maneja bien sus instancias y puede renderizar el peso normal demasiado fino. Convertimos
# la variable a instancias estáticas para que ambos motores lean pesos deterministas.
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FONTTOOLS="${PLUGIN_DIR}/.venv/bin/fonttools"
RALEWAY_VAR="${FONT_DIR}/Raleway-Regular.ttf"
if [ -x "${FONTTOOLS}" ] && [ -f "${RALEWAY_VAR}" ]; then
    tmp_var="$(mktemp /tmp/raleway-variable.XXXXXX.ttf)"
    cp -f "${RALEWAY_VAR}" "${tmp_var}"
    if "${FONTTOOLS}" varLib.instancer "${tmp_var}" wght=400 --static --update-name-table -o "${FONT_DIR}/Raleway-Regular.ttf"; then
        echo "== Raleway-Regular.ttf convertido a instancia estática wght=400 =="
    fi
    if "${FONTTOOLS}" varLib.instancer "${tmp_var}" wght=500 --static --update-name-table -o "${FONT_DIR}/Raleway-Medium.ttf"; then
        echo "== Raleway-Medium.ttf generado como instancia estática wght=500 =="
    fi
    rm -f "${tmp_var}"
fi

# Refrescar la caché de fontconfig para que rsvg-convert vea las familias por nombre.
if command -v fc-cache >/dev/null 2>&1; then
    echo "== fc-cache -f =="
    fc-cache -f "${FONT_DIR}" || fc-cache -f || true
else
    echo "WARNING: fc-cache no disponible; instala fontconfig en la imagen."
fi

echo "== install-fonts.sh terminado =="
# No abortamos el build por fallos puntuales de fuentes.
exit 0
