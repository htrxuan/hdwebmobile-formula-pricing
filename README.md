# HDWebmobile Formula Pricing

Price a product from customer-entered numbers using a formula -- evaluated safely, never with PHP's eval().

- **WordPress.org:** https://wordpress.org/plugins/hdwebmobile-formula-pricing/
- **Requires:** WordPress 6.9+, WooCommerce, PHP 7.4+
- **License:** GPLv2 or later

## Description

HDWebmobile Formula Pricing lets customers enter measurements or quantities on the product page, and prices the product live from a formula you write using those field names (e.g. `10 + width * height * 0.05` for a custom-cut material priced by area). This is a common real need for stores selling custom-sized fabric, framed prints, engraved items, or anything priced by dimension or quantity, not just a flat number.

## Why this plugin exists

A competing "Custom Product Addons Pro" plugin implemented formula-based pricing by passing the merchant's formula text straight into PHP's `eval()` at calculation time (CVE-2026-4001) -- an unauthenticated remote code execution vulnerability. This plugin closes that entire vulnerability class by construction:

* The formula is tokenized character-by-character into a strict set of allowed pieces: numbers, your own configured field names, and the four arithmetic operators plus parentheses. Anything else in the formula text -- any other letter sequence, any function-call-looking pattern, any special character -- causes the formula to be rejected outright when you save it.
* The token stream is evaluated by a small hand-written arithmetic parser. There is no `eval()`, no `create_function()`, no dynamic function dispatch of any kind anywhere in this plugin -- the only operations that can ever run are addition, subtraction, multiplication, and division of plain numbers.
* The customer-facing live price preview also never exposes your formula to the browser: it's computed by an AJAX call back to your own server, through the exact same safe evaluator used at checkout, so the formula text itself is never sent to a visitor's browser.
* The real price used at checkout is always recomputed server-side from the product's own stored fields and formula -- never trusted from what the browser submitted.

## Features

* Add up to 6 numeric input fields per product (label, variable name, min/max bounds, default value)
* Write a formula referencing those fields by name -- validated for safety the moment you save it
* Live price preview on the product page as the customer types, computed server-side
* Optional minimum/maximum price clamps, so no combination of inputs can produce an unreasonable price
* Every field's min/max bounds are enforced again server-side at add-to-cart time, not just in the browser
* Field values show on the cart, checkout, and order screens exactly as entered

## Development

Standard WordPress plugin structure:

```
hdwebmobile-formula-pricing.php    Bootstrap
includes/class-hdfp-activator.php
includes/class-hdfp-admin.php
includes/class-hdfp-cart.php
includes/class-hdfp-core.php
includes/class-hdfp-formula-evaluator.php
includes/class-hdfp-frontend.php
includes/class-hdfp-hub.php
includes/class-hdfp-product.php
```

Part of the [HDWebmobile](https://hdwebmobile.com/plugins/) suite of focused, single-purpose WooCommerce plugins.

## License

GPLv2 or later. See [LICENSE](LICENSE).

