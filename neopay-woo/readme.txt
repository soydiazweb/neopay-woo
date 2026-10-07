=== NeoPay para WooCommerce ===
Contributors: jonathandiaz
Tags: woocommerce, neopay, neonet, guatemala, 3d secure
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.0.2
License: GPLv2 or later

Pasarela NeoNet NeoPay por API REST con 3-D Secure: ambientes de pruebas y produccion, NeoCuotas, reversa automatica, anulacion al cancelar el pedido, comprobante y logs enmascarados.

== Description ==

Autor: Jonathan Diaz - https://www.soydiaz.com

Consulte README.md para la configuracion, el flujo 3-D Secure, los metadatos guardados en el pedido y los hooks disponibles.

== Changelog ==

= 1.0.2 =
* Nuevo ajuste "Codigo postal por defecto" (01001): NeoNet rechaza el pago con "PostalCode IS REQUIRED" cuando el pedido no trae codigo postal.

= 1.0.1 =
* Nuevo ajuste "Enviar datos de entrega (ShipTo)", desactivado por defecto: ShipTo viaja vacio porque NeoNet rechaza el pago con "CAMPO SHIPTO ... INVALIDO" en comercios que no lo tienen habilitado.

= 1.0.0 =
* Version inicial.
