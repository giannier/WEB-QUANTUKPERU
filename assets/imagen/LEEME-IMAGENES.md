# Imágenes del sitio — Quantuk Perú

**Formato del proyecto: `.webp`** para todo, salvo la excepción del final.

Las rutas ya están escritas en el HTML. Solo tenés que colocar los archivos
con estos nombres exactos y aparecen solos.

Mientras un archivo no exista se ve un degradado oscuro de marca en su lugar:
el sitio nunca queda roto ni muestra un ícono de imagen rota.

---

## Cómo nombrar archivos nuevos

Google lee el nombre del archivo para entender la imagen. La convención del
proyecto:

- Todo en **minúsculas**, palabras separadas por **guiones medios**
- Sin espacios, sin acentos, sin `ñ`, sin guiones bajos
- **Describí la imagen**, no la posición: `auditoria-interna-planta.webp`,
  nunca `img4.webp` ni `foto-servicio-3.webp`
- Tres a cinco palabras alcanzan

---

## Carrusel del hero ✓ COMPLETO

`assets/imagen/hero/` — 1488 × 716 px

| Archivo | Slide |
|---|---|
| `consultoria-compliance-corporativo.webp` | Especialistas en Compliance Corporativo |
| `certificacion-iso-9001-14001-45001.webp` | Sistemas de Gestión ISO |
| `mejora-continua-de-procesos.webp` | Mejora Continua de Procesos |
| `capacitacion-corporativa-empresas.webp` | Capacitación Corporativa |

Estas cuatro van en etiquetas `<img>` con texto alternativo, no como fondo
CSS. Si reemplazás alguna, actualizá también su `alt` en `index.html`: es lo
que leen Google Imágenes y los lectores de pantalla.

---

## Nosotros

`assets/imagen/nosotros/`

Ojo: esta carpeta se llama así por el **tema** (el equipo), no por la página.
Las dos primeras las usa el bloque "Quiénes somos" de `index.html`; las dos
últimas, la sección "Nuestra historia" de `nosotros.html`.

| Archivo | Dónde | Tamaño | Estado |
|---|---|---|---|
| `consultores-en-planta-cliente.webp` | index · principal | 900 × 1125 | ✓ |
| `reunion-de-trabajo-consultoria.webp` | index · insertada | 600 × 600 | ✓ |
| `consultoria-en-almacen-cliente.webp` | nosotros · principal | 900 × 1125 | pendiente |
| `manual-de-procesos-en-uso.webp` | nosotros · insertada | 600 × 600 | pendiente |
| `equipo-consultoria-quantuk-peru.webp` | nosotros · cabecera | 1920 × 700 | pendiente |

---

## Servicios — pendiente

`assets/imagen/servicios/` — 1280 × 720 cada una

| Archivo | Servicio |
|---|---|
| `servicios-consultoria-empresas-peru.webp` | Cabecera (1920 × 700) |
| `compliance-corporativo-ley-30424.webp` | Compliance Corporativo |
| `sistemas-gestion-iso-9001-14001-45001.webp` | Sistemas de Gestión ISO |
| `auditoria-interna-empresas.webp` | Auditorías Internas |
| `mejora-de-procesos-lean.webp` | Mejora Continua de Procesos |
| `seguridad-salud-trabajo-ley-29783.webp` | Seguridad y Salud en el Trabajo |
| `capacitacion-in-house-empresas.webp` | Capacitación Corporativa |

---

## Contacto

`assets/imagen/contacto/contacto-consultoria-quantuk-lima.webp` — 1920 × 700 ✓

---

## Encuadre de los banners

Los tres banners de cabecera se recortan **anclados arriba**
(`object-position: center top`), no al centro. Con el recorte centrado las
cabezas eran lo primero en perderse, porque las personas suelen quedar en la
mitad superior del encuadre.

Si generás un banner nuevo, tenelo en cuenta al componer: **lo que esté en el
tercio inferior de la foto probablemente no se vea.** El piso se pierde, y
está bien: no aporta nada.

---

## Redes sociales ✓

`assets/imagen/og/og-image.png` — 1200 × 630 px

Es la miniatura que aparece al compartir el enlace por WhatsApp, LinkedIn o
Facebook. Sin ella el enlace se comparte pelado, sin imagen.

**Va en PNG, no en WebP.** Es la única excepción al formato del proyecto:
varios lectores de vista previa todavía no interpretan WebP.

**No se genera con IA**, y es a propósito: haría un logo inventado y texto
ilegible. Está maquetada en HTML con el logo real y la tipografía del sitio.

El fuente editable está al lado, en `og/og-image.source.html`. Si cambia el
mensaje, editás ese archivo y lo re-exportás con el comando que trae adentro.

---

## Peso

Menos de **300 KB** por imagen. Las cuatro del hero están entre 39 y 66 KB,
que es el objetivo a mantener.

---

## Logo

`assets/imagen/LOGO ORIGINAL.png` — ya está. Se usa en el header, el drawer,
el footer, el favicon y la página 404.
