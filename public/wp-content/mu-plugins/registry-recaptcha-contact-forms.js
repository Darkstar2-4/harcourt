(function () {
	function init() {
		var gform = window.gform || {};

		if (typeof gform === 'undefined' || !gform.utils || typeof gform.utils.addAsyncFilter !== 'function') {
			return;
		}
		if (typeof grecaptcha === 'undefined' || !window.registryRecaptchaContact) {
			return;
		}

		var config = window.registryRecaptchaContact;

		function executeRecaptcha(form) {
			var input = form.querySelector('.' + config.field);
			if (!input || input.value.length) {
				return Promise.resolve();
			}
			return grecaptcha.execute(config.siteKey, { action: config.action }).then(function (token) {
				if (typeof token === 'string' && token.length) {
					input.value = token;
				}
			}).catch(function () {});
		}

		gform.utils.addAsyncFilter('gform/ajax/pre_ajax_validation', function (data) {
			return executeRecaptcha(data.form).then(function () { return data; });
		});

		gform.utils.addAsyncFilter('gform/submission/pre_submission', function (data) {
			var recaptchaRequired = data.submissionType === gform.submission.SUBMISSION_TYPE_SUBMIT
				|| data.submissionType === gform.submission.SUBMISSION_TYPE_NEXT;
			if (!recaptchaRequired || data.abort) {
				return Promise.resolve(data);
			}
			return executeRecaptcha(data.form).then(function () { return data; });
		});
	}

	if (document.readyState === 'complete') {
		init();
	} else {
		window.addEventListener('load', init);
	}
})();
