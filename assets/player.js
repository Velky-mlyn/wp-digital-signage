(function () {
	'use strict';

	const player = document.querySelector('.mds-player');
	const configElement = document.getElementById('mds-player-config');
	if (!player || !configElement) return;

	const config = JSON.parse(configElement.textContent);
	const items = Array.from(player.querySelectorAll('.mds-player-item'));
	let current = 0;
	let timer = null;
	let updatePending = false;
	let pointerTimer = null;
	const resumeKey = 'mds-resume:' + window.location.pathname;

	function saveResumePosition(index) {
		const item = items[index];
		if (!item) return;
		const id = item.dataset.itemId || '';
		const idIsUnique = id && items.filter(function (candidate) { return candidate.dataset.itemId === id; }).length === 1;
		try {
			window.sessionStorage.setItem(resumeKey, JSON.stringify({
				id: idIsUnique ? id : '',
				index: index,
				expires: Date.now() + 120000
			}));
		} catch (error) {}
	}

	function getResumePosition() {
		let saved = null;
		try {
			saved = JSON.parse(window.sessionStorage.getItem(resumeKey));
			window.sessionStorage.removeItem(resumeKey);
		} catch (error) {
			return 0;
		}
		const expires = saved ? Number.parseInt(saved.expires, 10) : 0;
		if (!saved || !Number.isInteger(expires) || expires < Date.now() || !items.length) return 0;
		if (saved.id) {
			const matchingIndex = items.findIndex(function (item) { return item.dataset.itemId === saved.id; });
			if (matchingIndex >= 0) return matchingIndex;
		}
		const savedIndex = Number.parseInt(saved.index, 10);
		return Number.isInteger(savedIndex) ? ((savedIndex % items.length) + items.length) % items.length : 0;
	}

	function clearPlayback() {
		if (timer) window.clearTimeout(timer);
		timer = null;
		items.forEach(function (item) {
			const video = item.querySelector('video');
			if (video) {
				video.onended = null;
				video.onerror = null;
				video.pause();
			}
		});
	}

	function advance(direction) {
		if (updatePending) {
			saveResumePosition((current + direction + items.length) % items.length);
			window.location.reload();
			return;
		}
		show((current + direction + items.length) % items.length);
	}

	function show(index) {
		if (!items.length) return;
		clearPlayback();
		current = index;
		items.forEach(function (item, itemIndex) {
			const active = itemIndex === current;
			item.classList.toggle('is-active', active);
			item.setAttribute('aria-hidden', active ? 'false' : 'true');
			item.inert = !active;
		});

		const item = items[current];
		const override = Number.parseInt(item.dataset.duration, 10) || 0;
		if (item.dataset.type === 'image') {
			timer = window.setTimeout(function () { advance(1); }, override || config.imageDuration);
			return;
		}

		const video = item.querySelector('video');
		video.currentTime = 0;
		video.onended = function () { advance(1); };
		video.onerror = function () {
			if (timer) window.clearTimeout(timer);
			timer = window.setTimeout(function () { advance(1); }, config.errorDuration);
		};
		if (override) timer = window.setTimeout(function () { advance(1); }, override);
		video.play().catch(function () {
			if (!override) timer = window.setTimeout(function () { advance(1); }, config.errorDuration);
		});
	}

	function pollForUpdates() {
		fetch(config.manifestUrl, { cache: 'no-store', credentials: 'same-origin' })
			.then(function (response) { return response.ok ? response.json() : null; })
			.then(function (manifest) {
				if (!manifest || manifest.version === config.version) return;
				updatePending = true;
				if (!items.length) window.location.reload();
			})
			.catch(function () {});
	}

	document.addEventListener('keydown', function (event) {
		if (event.key === 'ArrowRight' && items.length) advance(1);
		if (event.key === 'ArrowLeft' && items.length) advance(-1);
		if (event.key.toLowerCase() === 'r') window.location.reload();
		if (event.key.toLowerCase() === 'f') {
			if (document.fullscreenElement) document.exitFullscreen();
			else document.documentElement.requestFullscreen().catch(function () {});
		}
	});

	document.addEventListener('dblclick', function () {
		if (document.fullscreenElement) document.exitFullscreen();
		else document.documentElement.requestFullscreen().catch(function () {});
	});

	document.addEventListener('mousemove', function () {
		document.body.classList.add('is-pointer-visible');
		if (pointerTimer) window.clearTimeout(pointerTimer);
		pointerTimer = window.setTimeout(function () { document.body.classList.remove('is-pointer-visible'); }, 2500);
	});

	if (items.length) show(getResumePosition());
	window.setInterval(pollForUpdates, config.pollInterval);
}());
