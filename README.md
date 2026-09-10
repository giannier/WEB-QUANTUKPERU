# Quantuk Perú — Sitio corporativo

Sitio web de [Quantuk Perú](https://quantukperu.com), consultora en compliance
corporativo, sistemas de gestión ISO y mejora continua de procesos.

HTML, CSS y JavaScript puros. **Sin dependencias, sin build, sin instalación.**
Se abre `index.html` en el navegador y funciona.

---

## Estructura

```
├── index.html          Inicio — carrusel de 4 diapositivas
├── nosotros.html       Historia, misión/visión/valores
├── servicios.html      Seis servicios en panel maestro-detalle
├── contacto.html       Formulario, datos y mapa
├── 404.html
├── robots.txt · sitemap.xml
│
├── assets/
│   ├── css/
│   │   ├── base.css        Tokens de diseño, reset y tipografía
│   │   ├── layout.css      Cabecera, grilla de 12 columnas, pie
│   │   ├── components.css  Carrusel, pestañas, formularios, modal
│   │   └── pages.css       Bloques propios de cada página
│   ├── js/
│   │   ├── main.js         Cabecera fija, menú, animaciones, modal
│   │   ├── carousel.js     Carrusel del hero
│   │   └── forms.js        Validación y envío
│   └── imagen/             Ver assets/imagen/LEEME-IMAGENES.md
│
└── design-system/
    └── quantuk-peru/MASTER.md   Fuente de verdad del diseño
```

---

## Decisiones que conviene conocer antes de tocar el código

**La cabecera y el pie están duplicados en las cuatro páginas.** Es
deliberado: HTML plano funciona en cualquier hosting y Google lo indexa sin
problemas. El costo es que un cambio en el menú hay que replicarlo en los
cuatro archivos.

**Los formularios se entregan por WhatsApp**, no por correo. Al enviar se abre
`wa.me` con el mensaje ya redactado. Para migrar a correo: quitar el atributo
`data-whatsapp` del formulario y poner `data-endpoint="TU_URL"`. La ruta con
`fetch` ya está escrita en `assets/js/forms.js`.

**Todas las fotos van en etiquetas `<img>`, nunca como fondo CSS.** Una URL
relativa dentro de una variable CSS se resuelve contra la hoja de estilos y no
contra el documento, lo que produce rutas rotas de forma silenciosa. Además,
un fondo CSS no admite texto alternativo ni lo indexa Google Imágenes.

**El panel de servicios cambia de modo según el ancho**: pestañas en
escritorio, acordeón por debajo de 860 px. No es solo visual, también cambia
la semántica ARIA.

**Todas las imágenes en `.webp`**, salvo `og/og-image.png`: varios lectores de
vista previa de enlaces todavía no interpretan WebP.

---

## Pendiente antes de publicar

- [ ] Reemplazar las cifras del inicio por las reales (marcadas con `TODO`)
- [ ] Completar el equipo en `nosotros.html` (marcado con `TODO`)
- [ ] Poner la dirección exacta en el mapa de `contacto.html` (marcado con `TODO`)
- [ ] Reemplazar `hero/capacitacion-corporativa-empresas.webp`: tiene texto
      ilegible generado por IA en las pantallas del fondo

---

## URLs limpias

Las páginas se publican **sin la extensión**: `quantukperu.com/servicios`, no
`/servicios.html`. Lo resuelve el archivo `.htaccess`, que además:

- fuerza HTTPS y la versión sin `www`
- redirige con 301 las URLs viejas con `.html` a las limpias
- quita la barra final, que rompería las rutas relativas de los assets
- comprime, define caché y agrega cabeceras de seguridad

Funciona en Apache y en LiteSpeed, que son los motores que usa cPanel. **En
Nginx no se aplica**: ahí hace falta una directiva `try_files` en la
configuración del servidor.

Si algo fallara al publicar, renombrar `.htaccess` a `.htaccess.off` desactiva
todo y el sitio vuelve a funcionar con las URLs largas.

---

## Desarrollo

Como los enlaces del menú apuntan a rutas sin extensión, abrir `index.html`
con doble clic muestra la página pero **no permite navegar**. Para eso está el
servidor de vista previa, que replica el comportamiento del `.htaccess`:

```bash
python preview.py
```

Abre `http://localhost:5501` en el navegador. Requiere Python 3, nada más.

Para regenerar la miniatura de redes sociales tras editar su texto, el
comando está documentado dentro de `assets/imagen/og/og-image.source.html`.
