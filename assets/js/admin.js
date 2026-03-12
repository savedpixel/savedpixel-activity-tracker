/**
 * SavedPixel Activity Tracker admin interactions.
 */

document.addEventListener('DOMContentLoaded', function () {
	var trackerConfig = window.savedpixelActivityTracker || {};

	document.querySelectorAll('.spat-toggle-details').forEach(function (button) {
		button.addEventListener('click', function () {
			var targetId = button.getAttribute('data-target');
			var row = targetId ? document.getElementById(targetId) : null;
			if (!row) {
				return;
			}

			var isHidden = row.hasAttribute('hidden');
			if (isHidden) {
				row.removeAttribute('hidden');
			} else {
				row.setAttribute('hidden', 'hidden');
			}

			button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
			button.textContent = isHidden
				? (trackerConfig.hideLabel || 'Hide')
				: (trackerConfig.detailsLabel || 'Details');
		});
	});

	var testButton = document.getElementById('spat-test-email');
	var statusNode = document.getElementById('spat-test-email-status');
	if (!testButton || !statusNode) {
		return;
	}

	testButton.addEventListener('click', function () {
		var notificationEmail = document.getElementById('spat-notification-email');
		var emailContent = document.getElementById('spat-deactivation-email-content');

		if (!notificationEmail || !emailContent || !trackerConfig.ajaxUrl || !trackerConfig.testEmailNonce) {
			return;
		}

		statusNode.textContent = 'Sending...';
		testButton.disabled = true;

		var formData = new FormData();
		formData.append('action', 'savedpixel_activity_tracker_test_email');
		formData.append('nonce', trackerConfig.testEmailNonce);
		formData.append('notification_email', notificationEmail.value);
		formData.append('email_content', emailContent.value);

		fetch(trackerConfig.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				var message = payload && payload.data && payload.data.message ? payload.data.message : 'Request completed.';
				statusNode.textContent = message;
			})
			.catch(function () {
				statusNode.textContent = 'The test email request failed.';
			})
			.finally(function () {
				testButton.disabled = false;
			});
	});
});
