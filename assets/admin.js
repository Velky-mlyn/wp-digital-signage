(function() {
	'use strict';

	const container = document.getElementById('mds-item-list');
	const addButton = document.getElementById('mds-add-item');
	const bulkButton = document.getElementById('mds-add-media-bulk');
	const template = document.getElementById('mds-item-template');
	let dragged = null;
	const contentModal = document.getElementById('mds-content-modal');
	const contentSearch = document.getElementById('mds-content-search');
	const contentResults = document.getElementById('mds-content-results');
	const contentStatus = document.getElementById('mds-content-search-status');
	const contentLoadMore = document.getElementById('mds-content-load-more');
	let activePicker = null;
	let contentPage = 1;
	let contentRequest = 0;
	let contentSearchTimer = null;
	let contentItems = new Map();
	let contentReturnFocus = null;

	function createItemId() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(character) {
			const random = Math.floor(Math.random() * 16);
			const value = character === 'x' ? random : (random & 0x3) | 0x8;
			return value.toString(16);
		});
	}

	function chooseMedia(options, callback) {
		const frame = wp.media({
			title: options.title,
			button: {
				text: mdsAdmin.useMedia
			},
			library: {
				type: options.type
			},
			multiple: false
		});
		frame.on('select', function() {
			callback(frame.state().get('selection').first().toJSON());
		});
		frame.open();
	}

	function reindex() {
		container.querySelectorAll('.mds-item-editor').forEach(function(item, index) {
			item.dataset.itemIndex = index;
			item.querySelectorAll('[name]').forEach(function(field) {
				field.name = field.name.replace(/mds_items\[[^\]]+\]/, 'mds_items[' + index + ']');
			});
		});
	}

	function appendItem(media) {
		const index = container.querySelectorAll('.mds-item-editor').length;
		container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
		const item = container.lastElementChild;
		item.querySelector('.mds-item-id').value = createItemId();
		if (!media) return item;

		const type = media.type === 'video' ? 'video' : 'image';
		item.querySelector('.mds-item-type').value = type;
		item.querySelector('.mds-media-id').value = media.id;
		item.querySelector('.mds-item-summary').textContent = media.title || media.filename || mdsAdmin.untitled;
		item.querySelector('.mds-clear-media').disabled = false;
		const preview = item.querySelector('.mds-media-preview');
		preview.replaceChildren();
		if (type === 'image') {
			const image = document.createElement('img');
			image.src = media.sizes && media.sizes.thumbnail ? media.sizes.thumbnail.url : media.url;
			image.alt = '';
			preview.appendChild(image);
		} else {
			const code = document.createElement('code');
			code.textContent = media.filename || media.url.split('/').pop();
			preview.appendChild(code);
			const naturalLength = media.fileLength || (media.media_details && media.media_details.length_formatted) || '';
			if (naturalLength) item.querySelector('.mds-duration-help').textContent = naturalLength;
		}
		return item;
	}

	function updateContentSelection(picker, item) {
		const selection = picker.querySelector('.mds-content-selection');
		const oldId = selection.querySelector('.mds-content-id');
		const id = document.createElement(item.edit_url ? 'a' : 'span');
		id.className = 'mds-content-id';
		id.textContent = '#' + item.id;
		if (item.edit_url) {
			id.href = item.edit_url;
			id.target = '_blank';
			id.rel = 'noopener noreferrer';
			id.title = mdsAdmin.editContent;
		}
		oldId.replaceWith(id);
		picker.querySelector('.mds-event-id').value = item.id;
		selection.querySelector('.mds-content-title').textContent = item.title;
		selection.querySelector('.mds-content-type').textContent = item.type_label;
		selection.querySelector('.mds-content-status').textContent = item.status_label;
		selection.querySelector('.mds-content-date').textContent = item.date || '';
		const warning = selection.querySelector('.mds-content-warning');
		warning.textContent = item.warning || '';
		warning.hidden = !item.warning;
		selection.hidden = false;
		picker.querySelector('.mds-choose-content').textContent = mdsAdmin.changeContent;
		picker.querySelector('.mds-clear-content').disabled = false;
		const preview = picker.querySelector('.mds-event-preview');
		preview.replaceChildren();
		if (item.image_url) {
			const image = document.createElement('img');
			image.src = item.image_url;
			image.alt = '';
			preview.appendChild(image);
		}
		picker.closest('.mds-item-editor').querySelector('.mds-item-summary').textContent = item.title;
	}

	function clearContentSelection(picker) {
		picker.querySelector('.mds-event-id').value = '0';
		picker.querySelector('.mds-content-selection').hidden = true;
		picker.querySelector('.mds-choose-content').textContent = mdsAdmin.chooseContent;
		picker.querySelector('.mds-clear-content').disabled = true;
		picker.querySelector('.mds-event-preview').replaceChildren();
		picker.closest('.mds-item-editor').querySelector('.mds-item-summary').textContent = mdsAdmin.noEvent;
	}

	function closeContentPicker() {
		if (!contentModal || contentModal.hidden) return;
		window.clearTimeout(contentSearchTimer);
		contentRequest += 1;
		contentModal.hidden = true;
		document.body.classList.remove('mds-content-modal-open');
		activePicker = null;
		if (contentReturnFocus) contentReturnFocus.focus();
		contentReturnFocus = null;
	}

	function openContentPicker(picker, trigger) {
		if (!contentModal) return;
		activePicker = picker;
		contentReturnFocus = trigger;
		contentSearch.value = '';
		contentResults.replaceChildren();
		contentItems = new Map();
		contentStatus.textContent = mdsAdmin.searchPrompt;
		contentLoadMore.hidden = true;
		contentModal.hidden = false;
		document.body.classList.add('mds-content-modal-open');
		setTimeout(function() {
			contentSearch.focus();
		}, 0);
	}

	function createContentResult(item) {
		const result = document.createElement('article');
		result.className = 'mds-content-result';
		const details = document.createElement('div');
		details.className = 'mds-content-result-details';
		const title = document.createElement('strong');
		title.textContent = item.title;
		const meta = document.createElement('div');
		meta.className = 'mds-content-meta';
		[item.type_label, item.status_label, item.date].filter(Boolean).forEach(function(value) {
			const span = document.createElement('span');
			span.textContent = value;
			meta.appendChild(span);
		});
		const id = document.createElement(item.edit_url ? 'a' : 'span');
		id.className = 'mds-content-id';
		id.textContent = '#' + item.id;
		if (item.edit_url) {
			id.href = item.edit_url;
			id.target = '_blank';
			id.rel = 'noopener noreferrer';
			id.title = mdsAdmin.editContent;
		}
		meta.appendChild(id);
		details.append(title, meta);
		const select = document.createElement('button');
		select.type = 'button';
		select.className = 'button button-primary mds-select-content';
		select.dataset.contentId = item.id;
		select.textContent = mdsAdmin.selectContent;
		result.append(details, select);
		return result;
	}

	function searchContent(reset) {
		if (!activePicker || contentModal.hidden) return;
		const request = ++contentRequest;
		const term = contentSearch.value.trim();
		if (!/^\d+$/.test(term) && term.length < 2) {
			if (reset) contentResults.replaceChildren();
			contentStatus.textContent = mdsAdmin.searchPrompt;
			contentLoadMore.hidden = true;
			return;
		}
		if (reset) {
			contentPage = 1;
			contentResults.replaceChildren();
			contentItems = new Map();
		}
		contentStatus.textContent = mdsAdmin.searching;
		contentLoadMore.hidden = true;
		const url = new URL(mdsAdmin.ajaxUrl);
		url.searchParams.set('action', 'mds_search_events');
		url.searchParams.set('nonce', mdsAdmin.contentNonce);
		url.searchParams.set('q', term);
		url.searchParams.set('presentation_id', container.dataset.presentationId);
		url.searchParams.set('page_number', contentPage);
		fetch(url.toString(), {
				credentials: 'same-origin'
			})
			.then(function(response) {
				return response.json();
			})
			.then(function(response) {
				if (request !== contentRequest) return;
				if (!response.success) throw new Error('Search failed');
				response.data.items.forEach(function(item) {
					contentItems.set(String(item.id), item);
					contentResults.appendChild(createContentResult(item));
				});
				contentStatus.textContent = contentResults.children.length ? '' : mdsAdmin.noResults;
				contentLoadMore.hidden = !response.data.has_more;
			})
			.catch(function() {
				if (request === contentRequest) contentStatus.textContent = mdsAdmin.searchError;
			});
	}

	function move(item, direction) {
		const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
		if (!sibling) return;
		if (direction < 0) container.insertBefore(item, sibling);
		else container.insertBefore(sibling, item);
		reindex();
	}

	if (container && addButton && template) {
		addButton.addEventListener('click', function() {
			appendItem(null);
			reindex();
		});

		if (bulkButton) {
			bulkButton.addEventListener('click', function() {
				const frame = wp.media({
					title: mdsAdmin.bulkTitle,
					button: {
						text: mdsAdmin.useSelected
					},
					library: {
						type: ['image', 'video']
					},
					multiple: true
				});
				frame.on('select', function() {
					frame.state().get('selection').each(function(attachment) {
						const media = attachment.toJSON();
						if (media.type === 'image' || media.type === 'video') appendItem(media);
					});
					reindex();
				});
				frame.open();
			});
		}

		container.addEventListener('click', function(event) {
			const item = event.target.closest('.mds-item-editor');
			if (!item) return;
			const choose = event.target.closest('.mds-choose-content');
			if (choose) {
				openContentPicker(choose.closest('.mds-content-picker'), choose);
				return;
			}
			const clear = event.target.closest('.mds-clear-content');
			if (clear) {
				clearContentSelection(clear.closest('.mds-content-picker'));
				return;
			}
			if (event.target.closest('.mds-remove-item') && window.confirm(mdsAdmin.confirmRemove)) {
				item.remove();
				reindex();
				return;
			}
			if (event.target.closest('.mds-move-up')) {
				move(item, -1);
				return;
			}
			if (event.target.closest('.mds-move-down')) {
				move(item, 1);
				return;
			}
			if (event.target.closest('.mds-clear-media')) {
				item.querySelector('.mds-media-id').value = '';
				item.querySelector('.mds-media-preview').replaceChildren();
				item.querySelector('.mds-item-summary').textContent = mdsAdmin.untitled;
				event.target.closest('.mds-clear-media').disabled = true;
				return;
			}
			if (event.target.closest('.mds-choose-media')) {
				const type = item.querySelector('.mds-item-type').value;
				chooseMedia({
					type: type,
					title: type === 'video' ? mdsAdmin.videoTitle : mdsAdmin.imageTitle
				}, function(media) {
					item.querySelector('.mds-media-id').value = media.id;
					item.querySelector('.mds-item-summary').textContent = media.title || media.filename;
					const preview = item.querySelector('.mds-media-preview');
					preview.replaceChildren();
					if (type === 'image') {
						const image = document.createElement('img');
						image.src = media.sizes && media.sizes.thumbnail ? media.sizes.thumbnail.url : media.url;
						image.alt = '';
						preview.appendChild(image);
					} else {
						const code = document.createElement('code');
						code.textContent = media.filename || media.url.split('/').pop();
						preview.appendChild(code);
					}
					item.querySelector('.mds-clear-media').disabled = false;
				});
			}
		});

		container.addEventListener('change', function(event) {
			if (!event.target.matches('.mds-item-type')) return;
			const item = event.target.closest('.mds-item-editor');
			item.querySelector('.mds-media-id').value = '';
			item.querySelector('.mds-media-preview').replaceChildren();
			item.querySelector('.mds-item-summary').textContent = mdsAdmin.untitled;
			item.querySelector('.mds-clear-media').disabled = true;
			item.querySelector('.mds-duration-help').textContent = '';
			const isEvent = event.target.value === 'event';
			item.querySelector('.mds-media-field').hidden = isEvent;
			item.querySelector('.mds-event-field').hidden = !isEvent;
			if (isEvent) item.querySelector('.mds-item-summary').textContent = Number(item.querySelector('.mds-event-id').value) ? item.querySelector('.mds-content-title').textContent : mdsAdmin.noEvent;
		});

		container.addEventListener('dragstart', function(event) {
			dragged = event.target.closest('.mds-item-editor');
			if (!dragged) return;
			dragged.classList.add('is-dragging');
			event.dataTransfer.effectAllowed = 'move';
		});
		container.addEventListener('dragover', function(event) {
			if (!dragged) return;
			event.preventDefault();
			const target = event.target.closest('.mds-item-editor');
			if (!target || target === dragged || target.parentElement !== container || dragged.parentElement !== container) return;
			const box = target.getBoundingClientRect();
			container.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
		});
		container.addEventListener('dragend', function() {
			if (dragged) dragged.classList.remove('is-dragging');
			dragged = null;
			reindex();
		});
	}

	const logoField = document.querySelector('.mds-logo-field');
	if (logoField) {
		logoField.querySelector('.mds-choose-logo').addEventListener('click', function() {
			chooseMedia({
				type: 'image',
				title: mdsAdmin.logoTitle
			}, function(media) {
				logoField.querySelector('.mds-logo-id').value = media.id;
				const preview = logoField.querySelector('.mds-logo-preview');
				preview.replaceChildren();
				const image = document.createElement('img');
				image.src = media.sizes && media.sizes.thumbnail ? media.sizes.thumbnail.url : media.url;
				image.alt = '';
				preview.appendChild(image);
				logoField.querySelector('.mds-clear-logo').disabled = false;
			});
		});
		logoField.querySelector('.mds-clear-logo').addEventListener('click', function() {
			logoField.querySelector('.mds-logo-id').value = '';
			logoField.querySelector('.mds-logo-preview').replaceChildren();
			this.disabled = true;
		});
	}
	if (contentModal) {
		contentModal.addEventListener('click', function(event) {
			if (event.target.closest('.mds-close-content-modal') || event.target.matches('.mds-content-modal-backdrop')) {
				closeContentPicker();
				return;
			}
			const select = event.target.closest('.mds-select-content');
			if (select && activePicker) {
				const item = contentItems.get(select.dataset.contentId);
				if (item) updateContentSelection(activePicker, item);
				closeContentPicker();
			}
		});
		contentSearch.addEventListener('input', function() {
			contentRequest += 1;
			contentLoadMore.hidden = true;
			window.clearTimeout(contentSearchTimer);
			contentSearchTimer = window.setTimeout(function() {
				searchContent(true);
			}, 300);
		});
		contentSearch.addEventListener('keydown', function(event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				window.clearTimeout(contentSearchTimer);
				searchContent(true);
			}
		});
		contentLoadMore.addEventListener('click', function() {
			contentPage += 1;
			searchContent(false);
		});
		document.addEventListener('keydown', function(event) {
			if (contentModal.hidden) return;
			if (event.key === 'Escape') {
				closeContentPicker();
				return;
			}
			if (event.key !== 'Tab') return;
			const focusable = Array.from(contentModal.querySelectorAll('button:not([disabled]):not([hidden]), input:not([disabled]), select:not([disabled]), a[href]'))
				.filter(function(element) {
					return element.offsetParent !== null;
				});
			if (!focusable.length) return;
			const first = focusable[0];
			const last = focusable[focusable.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});
	}
}());