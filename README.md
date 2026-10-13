# Urbby para WooCommerce

Plugin de WordPress para vender el catálogo de una tienda WooCommerce en [Urbby](https://urbby.app).

**El stock vive en Urbby.** La tienda WooCommerce y Urbby descuentan del mismo lugar, así que nunca venden la misma unidad dos veces.

## Qué hace

| Qué | Dirección | Cómo |
|---|---|---|
| Catálogo: nombre, descripción, fotos, precio, oferta | Woo → Urbby | Al guardar un producto, y con el botón «Enviar catálogo». |
| Stock inicial | Woo → Urbby | Una sola vez, la primera vez que un producto llega a Urbby (la migración). |
| Venta en la tienda Woo | Woo → Urbby | En el checkout el plugin **aparta** el stock en Urbby. Si no hay, la compra no pasa. Al pagarse, la reserva se **confirma**. |
| Cambio de stock a mano en Woo (llegó mercadería) | Woo → Urbby | Viaja como **diferencia** («+10»), nunca como número fijo, así no pisa ventas. |
| Pedido de Urbby | Urbby → Woo | Llega por webhook firmado y crea una orden de Woo, a precio de compra. Las promociones de Urbby no tocan Woo. |
| Estado del pedido | Urbby → Woo | Procesando → Preparando (Urbby) → Lista para recolección (Urbby) → En camino (Urbby) → Completada / Cancelada. |
| Stock que muestra Woo | Urbby → Woo | Es una copia de Urbby. Se pone al día después de cada venta y cada 30 minutos. |

Cada 30 minutos el plugin también revisa los pedidos recientes de Urbby y crea los que un webhook no haya traído.

La orden de Woo de un pedido de Urbby es un registro:

- **No cobra:** el cobro y la liquidación viven en Urbby.
- **No descuenta stock:** Urbby ya lo descontó al vender.
- **No le escribe al comprador:** es cliente de Urbby.

## Requisitos

- WordPress 6.4+ y WooCommerce 8.0+ (probado con WordPress 7.1 y WooCommerce 11.2, con y sin HPOS).
- PHP 7.4+.
- Una credencial de la API pública de Urbby. Se crea en el portal del comercio, en Integraciones, con los permisos `catalog:read`, `catalog:write`, `inventory:write` y `orders:read`.
- Cada producto (o cada variación) con **«Gestionar inventario»** activado: Urbby necesita la cantidad. Se admiten productos simples y variables: hasta 3 atributos y 12 variaciones, cada variación con su propio stock.

## Instalación

1. Descargá el `.zip` del último [release](https://github.com/UrbbyApp/urbby-woocommerce/releases) y subilo en **Plugins → Añadir nuevo → Subir plugin**.
2. Abrí **WooCommerce → Urbby**, pegá el Client ID y el Client secret, guardá y tocá **Probar conexión**. La prueba revisa cada permiso que el plugin necesita, sin escribir nada.
3. En el portal de Urbby creá un webhook:
   - URL: la que muestra la pantalla (`https://tu-tienda/wp-json/urbby/v1/webhook`).
   - Eventos: `order_created`, `order_status_changed` y `shipment_status_changed`.
   - Después pegá su secreto en el plugin.
4. Elegí la categoría de Urbby de cada categoría de tu tienda (o una por defecto) y guardá.
5. Tocá **Enviar catálogo a Urbby**. Lo que no se pueda enviar aparece con su motivo en la columna «Urbby» de la lista de productos.

## Lo que la v1 no hace (a propósito)

- **Reembolsos parciales:** un reembolso total devuelve el stock en Urbby; uno parcial no. Ajustá el stock a mano si la mercadería volvió.
- **Cambiar cantidades de una línea de Urbby en el admin de Woo** no mueve el stock de Urbby. El pedido se maneja desde Urbby.
- **Copias del sitio:** si la dirección del sitio cambia (un staging clonado, un backup restaurado en otro dominio), el plugin deja de hablar con Urbby hasta que se vuelva a guardar la conexión. Así una copia no vende ni mueve el stock real.
- **Otro API:** para pruebas, el API se cambia con `define( 'URBBY_WC_API_BASE', 'https://…/v1' );` en `wp-config.php`. No se puede cambiar desde la pantalla.
- **Desinstalar** borra la credencial, pero conserva el vínculo de cada producto. Al reinstalar no se vuelve a migrar el stock, que pisaría el de Urbby.

## Desarrollo

```bash
composer install
composer test    # PHPUnit: las reglas puras (estados, firma, montos, referencias)
composer lint    # WordPress Coding Standards + compatibilidad con PHP 7.4
```

La prueba de punta a punta (WordPress + WooCommerce reales contra el API) vive junto al API de Urbby.

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).
