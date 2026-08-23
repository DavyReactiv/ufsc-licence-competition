(function () {
	'use strict';

	function installRevisionTokens() {
		var config = window.ufscResultConcurrency || {};
		var revisions = config.revisions || {};
		var forms = document.querySelectorAll('form input[name="fight_id"]');

		forms.forEach(function (fightInput) {
			var form = fightInput.form;
			var fightId = String(fightInput.value || '');
			var revision = revisions[fightId] || '';
			if (!form || !fightId || !revision || form.querySelector('input[name="expected_updated_at"]')) {
				return;
			}

			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = 'expected_updated_at';
			input.value = revision;
			form.appendChild(input);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', installRevisionTokens);
	} else {
		installRevisionTokens();
	}
}());
