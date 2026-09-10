#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Servidor de vista previa local para el sitio de Quantuk Perú.

Replica lo que hace el .htaccess en el hosting: sirve /servicios cuando el
archivo real es servicios.html. Sin esto, al abrir index.html con doble clic
los enlaces del menú no funcionan, porque apuntan a rutas sin extensión.

    python preview.py

Después abrir http://localhost:5501

Ctrl+C para detenerlo.
"""

import http.server
import socketserver
import functools
import os
import sys
import webbrowser
import posixpath
from urllib.parse import unquote, urlsplit

PORT = 5501
ROOT = os.path.dirname(os.path.abspath(__file__))


class CleanURLHandler(http.server.SimpleHTTPRequestHandler):
    """Resuelve rutas sin extensión y devuelve 404.html cuando no existe."""

    # Atajos que el .htaccess redirige con 301 en el hosting
    ALIAS = {"/inicio": "/", "/inicio/": "/", "/index.html": "/"}

    def do_GET(self):
        destino = self.ALIAS.get(urlsplit(self.path).path.lower())
        if destino:
            self.send_response(301)
            self.send_header("Location", destino)
            self.end_headers()
            return
        super().do_GET()

    def translate_path(self, path):
        # Ruta relativa pedida, sin querystring ni ancla
        rel = unquote(urlsplit(path).path).lstrip("/")
        rel = posixpath.normpath(rel) if rel else ""
        if rel in (".", ""):
            rel = "index.html"

        # Nunca salir del directorio del proyecto
        if rel.startswith("..") or os.path.isabs(rel):
            return os.path.join(ROOT, "index.html")

        full = os.path.join(ROOT, *rel.split("/"))

        # Existe tal cual: archivo o carpeta con índice
        if os.path.isfile(full):
            return full
        if os.path.isdir(full):
            idx = os.path.join(full, "index.html")
            if os.path.isfile(idx):
                return idx

        # /servicios -> servicios.html   (esto es lo que hace el .htaccess)
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
        # Sin caché: al recargar siempre se ve la última versión
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, fmt, *args):
        sys.stderr.write("  %s\n" % (fmt % args))


def main():
    handler = functools.partial(CleanURLHandler, directory=ROOT)
    socketserver.TCPServer.allow_reuse_address = True

    try:
        server = socketserver.TCPServer(("127.0.0.1", PORT), handler)
    except OSError:
        print("El puerto %d está ocupado. Cerrá el otro servidor y reintentá." % PORT)
        sys.exit(1)

    url = "http://localhost:%d" % PORT
    print("")
    print("  Quantuk Perú — vista previa")
    print("  %s" % url)
    print("  Ctrl+C para detener")
    print("")

    try:
        webbrowser.open(url)
    except Exception:
        pass

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n  Servidor detenido.")
        server.server_close()


if __name__ == "__main__":
    main()
