/**
 * Colour picker for the marker colour setting.
 *
 * HivePress has a Color field but renders no picker at all, so this adds WordPress's own. Four
 * behaviours here exist because of traps that have each cost a release before:
 *
 * 1. The field renders as <input type="color">, because a HivePress field's display type
 *    defaults to its class name. A native colour input reports #000000 when it is empty, so the
 *    server-rendered `value` attribute is read BEFORE Iris touches anything - it is the only
 *    honest record of whether a colour was ever chosen.
 * 2. Iris seeds an empty input with its own starting colour, black. Left alone, merely opening
 *    the settings page and pressing Save would persist black for a setting nobody touched, so
 *    the input is blanked again straight after initialising. Note what the guard does NOT do
 *    any more: it used to also blank a #000000 on submit whenever nothing had been stored
 *    before, which threw away a black an admin had deliberately picked. Iris writes every
 *    palette click and square drag straight into the input with jQuery .val(), which fires no
 *    DOM event at all, so "the value is black and I saw no input event" is indistinguishable
 *    from "nobody touched it". Real interaction is recorded through wpColorPicker's own change
 *    callback instead, which Iris does invoke.
 * 3. Switching the input to type="text" arms the field's HTML5 pattern, which rejects
 *    three-digit hex. Shorthand is expanded on change, on blur and on Enter - not on submit,
 *    because constraint validation runs before any submit handler.
 * 4. HivePress enforces the same pattern server-side, and a rejected value saves silently with
 *    the previous one left in place, so client-side expansion is not cosmetic.
 */
(function ($) {
	'use strict';

	var config = window.hpgpColorData || {};

	$(function () {
		$.each(config.fields || [], function (index, name) {
			var field = $('input[name="' + name + '"]');

			if (!field.length || typeof field.wpColorPicker !== 'function') {
				return;
			}

			// Read before Iris runs, or we read back its own seeded value.
			var stored = $.trim(field.attr('value') || ''),
				touched = false;

			field.attr('type', 'text');

			if (config.default) {
				field.attr('data-default-color', config.default);
			}

			field.wpColorPicker({
				defaultColor: config.default || false,

				// Iris calls this for a palette click, a square or strip drag and a typed value
				// alike. It is the only signal those first two produce.
				change: function () {
					touched = true;
				},

				clear: function () {
					touched = true;
					field.val('');
				}
			});

			if (!stored) {
				field.val('');
				field.trigger('change');

				// Initialising fires change once; that is not the admin touching anything.
				touched = false;
			}

			function expand() {
				var value = $.trim(field.val());

				if (/^#[0-9a-fA-F]{3}$/.test(value)) {
					field.val('#' + value.charAt(1) + value.charAt(1) + value.charAt(2) + value.charAt(2) + value.charAt(3) + value.charAt(3));
				}
			}

			field.on('change blur', expand);

			field.on('keydown', function (e) {
				if (e.key === 'Enter') {
					expand();
				}
			});

			field.closest('form').on('submit', function () {
				if (!stored && !touched && $.trim(field.val()).toLowerCase() === '#000000') {
					field.val('');
				}
			});
		});
	});
})(jQuery);
