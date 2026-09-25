# Mlýn Digital Signage

Full-screen, continuously looping image, video and event presentations managed in WordPress.

## Playback

Each published presentation has a dedicated URL at `/display/{slug}/`. Images use the presentation's default duration unless an item override is set. Videos are muted and use their natural duration unless an item override sets a maximum duration.

The editor can add one empty item or bulk-select a mixed set of images and videos from the Media Library. Bulk selections are appended in the order returned by the Media Library and remain individually reorderable and configurable.

The player checks for editorial and scheduling changes at a configurable interval. It reloads safely between items and resumes with the intended next item, or reloads immediately when the presentation is empty. Invalid media is skipped, and playback loops continuously.

All media is rendered with `object-fit: contain` against the configured solid background, so it is never cropped.

## Event slides

Choose **Event** as the media type, click **Choose linked event**, and search the modal by title, text or exact numeric ID. Like Flexible Slider, the picker searches as you type (after two characters), shows type/status/date badges and an edit-screen ID link, and offers **Load more** for additional results. Choose **Select** to link the event; **Change linked event** and **Clear linked event** update the selection. The slide follows the event's current generated or ready-made promo banner. If promo banners are disabled, missing or awaiting regeneration, it uses the clean featured image. Without Mlýn Event installed, it also uses the featured image.

A banner change is detected by the normal update polling; the screen reloads between slides. Existing image/video slides retain their media references. To follow an event automatically, select the Event type instead of manually selecting its banner in the Media Library.

Only published, password-free events with a usable image play. Event slides use the existing duration and **Show from / Show until** controls; the event date does not automatically expire a slide. Search results are paginated, including older events.

## Integration verification

`tests/event-integration-smoke.php` uses disposable fixtures to check generated/ready/disabled banners, stale-image fallback, manifest changes, visibility, scheduling, theme cards, clean slider images and real Event Intake re-imports. It requires Mlýn Event, Event Intake, Flexible Slider and the Velký Mlýn theme, and must run in a PHP environment with Imagick image codecs. It cleans up its fixtures by default.

## Changelog

### 1.2.0

- Add event slides using the current promo banner with featured-image fallback.
- Detect linked image changes in the playback manifest while retaining stable slide IDs and existing scheduling.
- Verify organizer re-imports preserve admin banner configuration and schedule regeneration.


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
