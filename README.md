# Mlýn Digital Signage

Full-screen, continuously looping image and video presentations managed in WordPress.

## Playback

Each published presentation has a dedicated URL at `/display/{slug}/`. Images use the presentation's default duration unless an item override is set. Videos are muted and use their natural duration unless an item override sets a maximum duration.

The editor can add one empty item or bulk-select a mixed set of images and videos from the Media Library. Bulk selections are appended in the order returned by the Media Library and remain individually reorderable and configurable.

The player checks for editorial and scheduling changes at a configurable interval. It reloads safely between items and resumes with the intended next item, or reloads immediately when the presentation is empty. Invalid media is skipped, and playback loops continuously.

All media is rendered with `object-fit: contain` against the configured solid background, so it is never cropped.

## Changelog

### 1.1.2

- Fix slide reordering by giving the inner item list an ID distinct from its WordPress metabox.
- Keep drag insertion targets inside the item list.

### 1.1.1

- Generate a unique internal ID for every newly added presentation item.
- Repair duplicate item IDs safely the next time an existing presentation is saved.
- Preserve the intended next or previous item across an update-triggered player reload.

## Windows kiosk mode

The player fills the web page viewport. Use Microsoft Edge or Google Chrome kiosk mode on the screen computer to remove browser controls. The F key or a double-click toggles the browser Fullscreen API after user interaction.

## Embed

```text
[mlyn_display id="main-display" height="100vh"]
```

Presentation posts and media references are deliberately retained when the plugin is uninstalled.
