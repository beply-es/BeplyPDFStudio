# PluginTemplate

Plantilla base para crear nuevos plugins de FacturaScripts.

## Estructura

```
PluginTemplate/
├── .github/
│   └── workflows/
│       └── release.yml      # CI/CD: crea releases automaticos
├── Assets/
│   ├── CSS/                 # Estilos CSS
│   ├── JS/                  # Scripts JavaScript
│   └── Images/              # Imagenes y recursos
├── Controller/              # Controladores
├── Lib/                     # Clases de libreria
├── Model/                   # Modelos de datos
├── Translation/             # Archivos de traduccion
├── View/                    # Plantillas Twig
├── facturascripts.ini       # Configuracion del plugin
├── Init.php                 # Clase de inicializacion
└── README.md
```

## Uso

1. Clona este repositorio
2. Renombra la carpeta al nombre de tu plugin
3. Actualiza `facturascripts.ini` con los datos de tu plugin
4. Actualiza el namespace en `Init.php`
5. Comienza a desarrollar

## CI/CD

El workflow de GitHub Actions crea automaticamente un release cuando:
- Se hace push a la rama `main`
- La version en `facturascripts.ini` es nueva (no existe tag previo)

El release incluye:
- Un archivo ZIP listo para instalar en FacturaScripts
- Notas de version generadas automaticamente desde los commits

## Requisitos

- FacturaScripts 2025+
- PHP 8.1+

## Licencia

LGPL-3.0-or-later
