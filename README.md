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
├── privacidad.html     Política de privacidad (Ley 29733)
├── libro-de-reclamaciones.html   Libro de Reclamaciones (Ley 29571)
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
├── api/                Backend del Libro de Reclamaciones (PHP)
│   ├── reclamo.php         Endpoint: valida, numera, genera PDF y envía
│   ├── token.php           Emite el token de sesión del formulario
│   ├── config.php          Configuración (sin credenciales)
│   ├── src/                Seguridad, Validador, Correlativo, HojaPdf, Notificador
│   ├── lib/                FPDF y PHPMailer
│   └── storage/            Hojas archivadas, contador y logs (no se sirve por HTTP)
│
├── preview.py          Servidor local con URLs limpias
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

## Libro de Reclamaciones

Formulario en `/libro-de-reclamaciones` con backend PHP. Al enviarlo genera
una Hoja de Reclamación en PDF de **una sola página**, la archiva en el
servidor y la remite al correo del consumidor con copia oculta a la bandeja
interna.

**Requiere PHP 8.0 o superior**, disponible en cualquier hosting cPanel. El
resto del sitio sigue siendo estático: si el backend falla, las demás páginas
no se ven afectadas.

### Al desplegar

1. Copiar `api/config.secret.example.php` a `api/config.secret.php`
2. Poner ahí la contraseña del buzón `reclamaciones@quantukperu.com`
3. Dar permiso de escritura a `api/storage/` (755 suele bastar)

`config.secret.php` está excluido del repositorio a propósito: una contraseña
que entra al historial de Git ya no se puede borrar de ahí.

### Base legal implementada

- Contenido mínimo de la Hoja según el art. 5 del <span>D.S. 011-2011-PCM</span>
- Numeración correlativa protegida contra registros simultáneos
- Distinción entre reclamo y queja con sus definiciones legales
- Plazo de respuesta de **15 días hábiles improrrogables** (art. 24 de la
  Ley 29571, modificado por la Ley 31435). Ojo: el reglamento de 2011 decía
  30 días calendario y quedó desactualizado; muchos formatos que circulan
  todavía arrastran esa cifra
- Conservación de las hojas por dos años (art. 12)
- La casilla de conformidad reemplaza a la firma, como admite el art. 5 para
  el libro virtual
- Aviso del Libro visible en el pie de todas las páginas (art. 3.5)

### Defensas del formulario

Límite por IP con ventana deslizante (3 por hora, 8 por día) más un freno
global, token de un solo uso ligado a la sesión, tiempo mínimo de llenado,
campo trampa para robots, verificación de origen, tope de tamaño de la
petición, validación estricta en el servidor y bloqueo de inyección de
cabeceras en el correo. Los mensajes de error hacia el navegador son
genéricos; el detalle queda en `api/storage/log/`.

---

## Pendiente antes de publicar

- [ ] **Inscribir el banco de datos personales** en el Registro Nacional de la
      ANPD. La Ley 29733 lo exige y la política de privacidad ya lo declara
      (marcado con `TODO legal` en `privacidad.html`)
- [ ] Revisión de la política de privacidad por el abogado de la empresa
- [ ] Reemplazar las cifras del inicio por las reales (marcadas con `TODO`)
- [ ] Reemplazar `hero/capacitacion-corporativa-empresas.webp`: tiene texto
      ilegible generado por IA en las pantallas del fondo
- [ ] Cargar la contraseña SMTP en `api/config.secret.php` y enviar un
      reclamo de prueba para confirmar que el correo llega

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

Abre `http://localhost:5510` en el navegador.

**No uses la extensión Live Server de VS Code para probar el formulario.**
No ejecuta PHP: devuelve el código fuente como texto y rechaza los envíos
con `501 Unsupported method`. Además usa el puerto 5501, así que si ambos
se levantan a la vez las peticiones caen en uno o en otro sin criterio.
preview.py usa el 5510 justamente para no chocar con ella.

Para el Libro de Reclamaciones hace falta PHP 8. preview.py lo busca en el
PATH y en las rutas habituales de instalación; si no lo encuentra, el sitio
se ve igual pero el formulario avisa con un mensaje claro.

Para regenerar la miniatura de redes sociales tras editar su texto, el
comando está documentado dentro de `assets/imagen/og/og-image.source.html`.
