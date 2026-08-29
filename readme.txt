=== HDWebmobile Formula Pricing ===
Contributors: htrxuan
Donate link: https://paypal.me/htrxuan/20
Tags: woocommerce, dynamic pricing, formula, calculator, custom pricing
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Price a product from customer-entered numbers using a formula -- evaluated safely, never with PHP's eval().

== Description ==

HDWebmobile Formula Pricing lets customers enter measurements or quantities on the product page, and prices the product live from a formula you write using those field names (e.g. `10 + width * height * 0.05` for a custom-cut material priced by area). This is a common real need for stores selling custom-sized fabric, framed prints, engraved items, or anything priced by dimension or quantity, not just a flat number.

= Why this plugin exists =
A competing "Custom Product Addons Pro" plugin implemented formula-based pricing by passing the merchant's formula text straight into PHP's `eval()` at calculation time (CVE-2026-4001) -- an unauthenticated remote code execution vulnerability. This plugin closes that entire vulnerability class by construction:

* The formula is tokenized character-by-character into a strict set of allowed pieces: numbers, your own configured field names, and the four arithmetic operators plus parentheses. Anything else in the formula text -- any other letter sequence, any function-call-looking pattern, any special character -- causes the formula to be rejected outright when you save it.
* The token stream is evaluated by a small hand-written arithmetic parser. There is no `eval()`, no `create_function()`, no dynamic function dispatch of any kind anywhere in this plugin -- the only operations that can ever run are addition, subtraction, multiplication, and division of plain numbers.
* The customer-facing live price preview also never exposes your formula to the browser: it's computed by an AJAX call back to your own server, through the exact same safe evaluator used at checkout, so the formula text itself is never sent to a visitor's browser.
* The real price used at checkout is always recomputed server-side from the product's own stored fields and formula -- never trusted from what the browser submitted.

= Key Features =
* Add up to 6 numeric input fields per product (label, variable name, min/max bounds, default value)
* Write a formula referencing those fields by name -- validated for safety the moment you save it
* Live price preview on the product page as the customer types, computed server-side
* Optional minimum/maximum price clamps, so no combination of inputs can produce an unreasonable price
* Every field's min/max bounds are enforced again server-side at add-to-cart time, not just in the browser
* Field values show on the cart, checkout, and order screens exactly as entered

= Limitations (please read before installing) =
* Simple products only in this version -- no variable-product support
* Formulas support only +, -, *, /, and parentheses (no exponents, square roots, or conditional logic) -- deliberately, since a richer grammar is a larger safety surface to get right
* No formula "test" button in the admin beyond the save-time validation -- if a formula is rejected, the error message names exactly what's wrong (an unknown variable, a bad character, division by zero with the values you tried)
* Requires a Regular Price to be set on the product (even a nominal placeholder) -- this is standard WooCommerce behavior for any product with no price at all, not something this plugin can work around

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/hdwebmobile-formula-pricing` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress. WooCommerce must already be installed and active.
3. Edit a simple product, open its new "Formula Pricing" tab under Product Data, add your fields and formula.
4. On the product's "General" tab, set a Regular Price -- WooCommerce hides the entire purchase form on a product with no price at all, even with formula pricing enabled, so enter at least a nominal placeholder amount (it will be overridden the moment a customer enters a value).

== How to Use ==

= 1. Add your input fields =
On a simple product's "Formula Pricing" tab, click "+ Add Field" for each number the customer should enter (e.g. "Width in cm" with variable name `width`). Set a minimum, an optional maximum, and a default value for each.

= 2. Write the formula =
In the Formula box, write an expression using your field names exactly as shown, e.g. `10 + width * height * 0.05`. Save the product -- if the formula uses anything other than your field names, numbers, and `+ - * / ( )`, you'll see exactly what's wrong and the formula won't be saved until it's fixed.

= 3. What the customer sees =
The product page shows your input fields above the Add to Cart button, with a live estimated price that updates as they type. The real price is confirmed the moment the product is actually added to the cart.

= 4. Keeping prices sane =
Set an optional minimum and/or maximum price on the same tab -- no matter what a customer enters (within their allowed field ranges), the final price is clamped to stay within those bounds.

== Screenshots ==

1. The Formula Pricing tab on a product's edit screen -- fields and formula configuration.
2. The customer-facing input fields and live price preview on the product page.
3. A cart line item showing the entered measurements alongside the computed price.

== Changelog ==

= 1.0.0 =
* Initial release: per-product numeric input fields, safe formula evaluation via a hand-written tokenizer and recursive-descent parser (no eval()), server-side live price preview via AJAX, min/max price clamping, field values shown on cart/checkout/order screens.
