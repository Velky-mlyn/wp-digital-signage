(function () {
	'use strict';

	const container = document.getElementById('mds-items');
	const addButton = document.getElementById('mds-add-item');
	const bulkButton = document.getElementById('mds-add-media-bulk');
	const template = document.getElementById('mds-item-template');
	let dragged = null;

	function chooseMedia(options, callback) {
		const frame = wp.media({
			title: options.title,
			button: { text: mdsAdmin.useMedia },
			library: { type: options.type },
			multiple: false
		});
		frame.on('select', function () { callback(frame.state().get('selection').first().toJSON()); });
		frame.open();
	}

	function reindex() {
		container.querySelectorAll('.mds-item-editor').forEach(function (item, index) {
			item.dataset.itemIndex = index;
			item.querySelectorAll('[name]').forEach(function (field) {
				field.name = field.name.replace(/mds_items\[[^\]]+\]/, 'mds_items[' + index + ']');
			});
		});
	}

	function appendItem(media) {
		const index = container.querySelectorAll('.mds-item-editor').length;
		container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
		const item = container.lastElementChild;
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

	function move(item, direction) {
		const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
		if (!sibling) return;
		if (direction < 0) container.insertBefore(item, sibling);
		else container.insertBefore(sibling, item);
		reindex();
	}

	if (container && addButton && template) {
		addButton.addEventListener('click', function () {
			appendItem(null);
			reindex();
		});

		if (bulkButton) {
			bulkButton.addEventListener('click', function () {
				const frame = wp.media({
					title: mdsAdmin.bulkTitle,
					button: { text: mdsAdmin.useSelected },
					library: { type: ['image', 'video'] },
					multiple: true
				});
				frame.on('select', function () {
					frame.state().get('selection').each(function (attachment) {
						const media = attachment.toJSON();
						if (media.type === 'image' || media.type === 'video') appendItem(media);
					});
					reindex();
				});
				frame.open();
			});
		}

		container.addEventListener('click', function (event) {
			const item = event.target.closest('.mds-item-editor');
			if (!item) return;
			if (event.target.closest('.mds-remove-item') && window.confirm(mdsAdmin.confirmRemove)) {
				item.remove(); reindex(); return;
			}
			if (event.target.closest('.mds-move-up')) { move(item, -1); return; }
			if (event.target.closest('.mds-move-down')) { move(item, 1); return; }
			if (event.target.closest('.mds-clear-media')) {
				item.querySelector('.mds-media-id').value = '';
				item.querySelector('.mds-media-preview').replaceChildren();
				item.querySelector('.mds-item-summary').textContent = mdsAdmin.untitled;
				event.target.closest('.mds-clear-media').disabled = true;
				return;
			}
			if (event.target.closest('.mds-choose-media')) {
				const type = item.querySelector('.mds-item-type').value;
				chooseMedia({ type: type, title: type === 'video' ? mdsAdmin.videoTitle : mdsAdmin.imageTitle }, function (media) {
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

		container.addEventListener('change', function (event) {
			if (!event.target.matches('.mds-item-type')) return;
			const item = event.target.closest('.mds-item-editor');
			item.querySelector('.mds-media-id').value = '';
			item.querySelector('.mds-media-preview').replaceChildren();
			item.querySelector('.mds-item-summary').textContent = mdsAdmin.untitled;
			item.querySelector('.mds-clear-media').disabled = true;
			item.querySelector('.mds-duration-help').textContent = '';
		});

		container.addEventListener('dragstart', function (event) {
			dragged = event.target.closest('.mds-item-editor');
			if (!dragged) return;
			dragged.classList.add('is-dragging');
			event.dataTransfer.effectAllowed = 'move';
		});
		container.addEventListener('dragover', function (event) {
			if (!dragged) return;
			event.preventDefault();
			const target = event.target.closest('.mds-item-editor');
			if (!target || target === dragged) return;
			const box = target.getBoundingClientRect();
			container.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
		});
		container.addEventListener('dragend', function () {
			if (dragged) dragged.classList.remove('is-dragging');
			dragged = null;
			reindex();
		});
	}

	const logoField = document.querySelector('.mds-logo-field');
	if (logoField) {
		logoField.querySelector('.mds-choose-logo').addEventListener('click', function () {
			chooseMedia({ type: 'image', title: mdsAdmin.logoTitle }, function (media) {
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
		logoField.querySelector('.mds-clear-logo').addEventListener('click', function () {
			logoField.querySelector('.mds-logo-id').value = '';
			logoField.querySelector('.mds-logo-preview').replaceChildren();
			this.disabled = true;
		});
	}
}());
