#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Servidor de vista previa local del sitio de Quantuk Perú.

Hace dos cosas que el hosting hace y un servidor estático no:

  1. Resuelve las URLs sin extensión: sirve servicios.html cuando piden
     /servicios, igual que la regla del .htaccess.

  2. Ejecuta el PHP del Libro de Reclamaciones. Levanta por detrás el
     servidor incorporado de PHP y le reenvía todo lo que termine en .php.
     Sin esto, un POST al formulario devuelve "501 Unsupported method",
     porque el servidor de Python solo sabe responder GET.

Uso:

    python preview.py

Después abrir http://localhost:5501 · Ctrl+C para detenerlo.
"""

import http.server
import socketserver
import functools
import os
import sys
import shutil
import subprocess
import posixpath
import webbrowser
import urllib.request
import urllib.error
import time
from urllib.parse import unquote, urlsplit

# Puertos propios, deliberadamente lejos del 5501 que usa la extensión
# Live Server de VS Code. Compartir puerto con ella hace que las peticiones
# caigan en un servidor o en el otro de forma impredecible: Live Server no
# ejecuta PHP, así que devuelve el código fuente como texto y rechaza los
# POST con "501 Unsupported method".
PORT = 5510
PORT_PHP = 5511
ROOT = os.path.dirname(os.path.abspath(__file__))

# Dónde buscar PHP, en orden. La primera ruta es la instalación local
# portable; después se prueba lo que haya en el PATH del sistema.
CANDIDATOS_PHP = [
    os.path.join(os.environ.get("LOCALAPPDATA", ""), "Programs", "php", "php.exe"),
    r"C:\php\php.exe",
    r"C:\xampp\php\php.exe",
    r"C:\laragon\bin\php\php.exe",
]


def buscar_php():
    en_path = shutil.which("php")
    if en_path:
        return en_path
    for ruta in CANDIDATOS_PHP:
        if ruta and os.path.isfile(ruta):
            return ruta
    return None


PHP_BIN = buscar_php()
php_proceso = None


def arrancar_php():
    """Levanta el servidor incorporado de PHP en segundo plano."""
    global php_proceso
    if not PHP_BIN:
        return False

    ini = os.path.join(os.path.dirname(PHP_BIN), "php.ini")
    cmd = [PHP_BIN]
    if os.path.isfile(ini):
        cmd += ["-c", ini]
    cmd += ["-S", "127.0.0.1:%d" % PORT_PHP, "-t", ROOT]

    try:
        php_proceso = subprocess.Popen(
            cmd,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            cwd=ROOT,
        )
    except OSError:
        return False

    # Esperar a que responda antes de aceptar peticiones
    for _ in range(40):
        try:
            urllib.request.urlopen("http://127.0.0.1:%d/" % PORT_PHP, timeout=1)
            return True
        except urllib.error.HTTPError:
            return True          # responde, aunque sea un 404: ya está arriba
        except Exception:
            time.sleep(0.25)

    return False


class Handler(http.server.SimpleHTTPRequestHandler):
    """Sirve el sitio estático y delega el PHP."""

    ALIAS = {"/inicio": "/", "/inicio/": "/", "/index.html": "/"}
    php_listo = False

    # -- Rutas -----------------------------------------------------------

    def es_php(self):
        return urlsplit(self.path).path.lower().endswith(".php")

    def do_GET(self):
        if self.es_php():
            return self.delegar("GET")

        destino = self.ALIAS.get(urlsplit(self.path).path.lower())
        if destino:
            self.send_response(301)
            self.send_header("Location", destino)
            self.end_headers()
            return

        super().do_GET()

    def do_HEAD(self):
        if self.es_php():
            return self.delegar("HEAD")
        super().do_HEAD()

    def do_POST(self):
        return self.delegar("POST")

    # -- Puente hacia PHP -------------------------------------------------

    def delegar(self, metodo):
        if not Handler.php_listo:
            return self.sin_php()

        largo = int(self.headers.get("Content-Length") or 0)
        cuerpo = self.rfile.read(largo) if largo else None

        req = urllib.request.Request(
            "http://127.0.0.1:%d%s" % (PORT_PHP, self.path),
            data=cuerpo,
            method=metodo,
        )

        # Se reenvían las cabeceras del navegador. Hop-by-hop fuera: son del
        # tramo de conexión, no del mensaje, y confunden al destino.
        excluir = {"connection", "keep-alive", "transfer-encoding",
                   "upgrade", "proxy-connection", "te", "trailer"}

        # El Host original se conserva a propósito. El endpoint compara la
        # cabecera Origin contra el Host para rechazar peticiones de otros
        # dominios; si acá se reescribiera al puerto interno de PHP, esa
        # comprobación vería dos valores distintos y bloquearía el envío
        # legítimo por "origen cruzado".
        for k, v in self.headers.items():
            if k.lower() not in excluir:
                req.add_header(k, v)

        try:
            r = urllib.request.urlopen(req, timeout=60)
            estado, cabeceras, datos = r.status, r.headers, r.read()
        except urllib.error.HTTPError as e:
            estado, cabeceras, datos = e.code, e.headers, e.read()
        except Exception as e:
            self.responder_json(502, '{"ok":false,"mensaje":"PHP no respondió: %s"}'
                                % str(e).replace('"', "'"))
            return

        self.send_response(estado)
        for k, v in cabeceras.items():
            if k.lower() not in ("transfer-encoding", "connection", "server", "date"):
                self.send_header(k, v)
        self.send_header("Content-Length", str(len(datos)))
        self.end_headers()

        if metodo != "HEAD":
            self.wfile.write(datos)

    def sin_php(self):
        """Mensaje claro en vez del críptico 501 del servidor de Python."""
        mensaje = (
            "PHP no está disponible, así que el Libro de Reclamaciones no puede "
            "funcionar en la vista previa local. El resto del sitio sí. "
            "Instala PHP 8 y vuelve a ejecutar preview.py, o prueba el formulario "
            "directamente en el hosting."
        )
        self.responder_json(503, '{"ok":false,"mensaje":"%s"}' % mensaje)

    def responder_json(self, codigo, cuerpo):
        datos = cuerpo.encode("utf-8")
        self.send_response(codigo)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(datos)))
        self.end_headers()
        self.wfile.write(datos)

    # -- Archivos estáticos -----------------------------------------------

    def translate_path(self, path):
        rel = unquote(urlsplit(path).path).lstrip("/")
        rel = posixpath.normpath(rel) if rel else ""
        if rel in (".", ""):
            rel = "index.html"

        if rel.startswith("..") or os.path.isabs(rel):
            return os.path.join(ROOT, "index.html")

        full = os.path.join(ROOT, *rel.split("/"))

        if os.path.isfile(full):
            return full
        if os.path.isdir(full):
            idx = os.path.join(full, "index.html")
            if os.path.isfile(idx):
                return idx

        # /servicios -> servicios.html, igual que el .htaccess
        if os.path.isfile(full + ".html"):
            return full + ".html"

        return full

    def send_error(self, code, message=None, explain=None):
        if code == 404:
            page = os.path.join(ROOT, "404.html")
            if os.path.isfile(page):
                body = open(page, "rb").read()
                self.send_response(404)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                if self.command != "HEAD":
                    self.wfile.write(body)
                return
        super().send_error(code, message, explain)

    def end_headers(self):
        if not self.es_php():
            self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, fmt, *args):
        sys.stderr.write("  %s\n" % (fmt % args))


def puerto_ocupado(puerto):
    """¿Hay alguien escuchando ya en ese puerto?"""
    import socket
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.settimeout(0.4)
        return s.connect_ex(("127.0.0.1", puerto)) == 0


def main():
    # Comprobar ANTES de atarse. En Windows, allow_reuse_address permite que
    # dos procesos compartan el mismo puerto y las peticiones caen en uno o
    # en otro sin criterio: por eso acá se verifica primero y se falla claro.
    if puerto_ocupado(PORT):
        print("")
        print("  El puerto %d ya está ocupado por otro programa." % PORT)
        print("  Ciérralo y vuelve a intentar, o cambia PORT en este archivo.")
        print("")
        sys.exit(1)

    Handler.php_listo = arrancar_php()

    handler = functools.partial(Handler, directory=ROOT)
    # False a propósito: si el puerto está tomado, que falle en vez de
    # convivir con el otro servidor.
    socketserver.TCPServer.allow_reuse_address = False

    try:
        servidor = socketserver.TCPServer(("127.0.0.1", PORT), handler)
    except OSError as e:
        print("  No se pudo abrir el puerto %d: %s" % (PORT, e))
        sys.exit(1)

    url = "http://localhost:%d" % PORT
    print("")
    print("  Quantuk Perú — vista previa")
    print("  %s" % url)

    if Handler.php_listo:
        print("  PHP %s — el Libro de Reclamaciones funciona" % os.path.basename(PHP_BIN))
    else:
        print("  Sin PHP: el sitio se ve, pero el Libro de Reclamaciones no envía.")
        print("  Instala PHP 8 para probarlo en local.")

    print("  Ctrl+C para detener")
    print("")

    try:
        webbrowser.open(url)
    except Exception:
        pass

    try:
        servidor.serve_forever()
    except KeyboardInterrupt:
        print("\n  Deteniendo...")
    finally:
        servidor.server_close()
        if php_proceso:
            php_proceso.terminate()
        print("  Listo.")


if __name__ == "__main__":
    main()
