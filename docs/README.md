# ActivityPub

Connect HumHub to the Fediverse, the decentralized meta network of Mastodon, Lemmy, Pixelfed, Misskey, and many other servers, through the ActivityPub protocol.

## Overview

The module makes your HumHub instance a member of the Fediverse: local users can be followed from other servers, and they can follow, like, and reply to remote accounts from inside HumHub.

### Local profiles on the Fediverse

- Each profile visible to guests becomes a followable ActivityPub `Person` with an `@username@your-domain` handle, discoverable through WebFinger and NodeInfo.
- Users opt in to publishing. Only public content visible to guests is federated: posts with image and file attachments, edits, and deletions.
- Name, bio, avatar, and banner are shared with remote servers, and any later change is pushed to followers.
- Remote follows from other servers are accepted automatically. The "remote follow" button on Mastodon and similar servers works too (`/authorize_interaction`).
- Replies and likes from remote servers become regular HumHub comments and likes, on posts as well as on individual comments.

### Following the Fediverse from HumHub

- Follow any Fediverse account by entering its `@user@domain` handle. Posts from accounts you follow appear in your dashboard.
- Liking or commenting on a remote post sends the like or reply back to its author, and unliking sends an undo.
- Remote posts show their original favourite and boost counts, plus a "View original" link to the source thread.
- When the author edits or deletes a remote post or comment, the change also applies in HumHub.
- Mastodon custom emoji and trailing hashtag blocks are supported. Hashtags become HumHub topics.
- Lemmy-family communities (Lemmy, Mbin, PieFed, etc.) can be subscribed to and mirrored into a HumHub Space.

### Remote users

- Remote accounts are represented by login-disabled "federated" users, governed by a dedicated user source. Their synced profile is read-only and they have no email address.
- Federated users are placed in a dedicated "Remote Users" group, which you can change in the module settings, and are kept out of the default group.
- A remote user's profile links to the original account and shows its real follower and following counts instead of local counters.
- A "Fediverse" profile field category holds the handle and display name. The handle can be shown as the display name subtitle under Administration → Settings → Appearance.

### User settings

The user account menu has a **Fediverse** entry. It shows your handle, lets you opt in to publishing, and lets you follow or unfollow remote accounts and communities.

### Administration

Under Administration → Modules → ActivityPub → Configure:
- Enable or disable federation for the whole site
- Choose the group for federated (remote) users
- Optionally log federation activity (raw JSON and outcome) to a capped debug log, with a viewer
- See basic statistics: local and remote actors, follows, federated and ingested posts

### Security

- Inbound activities require a valid HTTP Signature with a digest, bound to the sending actor and within a freshness window.
- Outbound fetches are signed by the instance actor, which supports servers running in "authorized fetch" or secure mode.
- All remote fetches are SSRF-guarded and HTTPS-only. Remote media is limited to raster image types and capped in size.
- Failed deliveries are retried on transient errors, and the inbox is rate-limited per IP.

## Requirements

- HumHub 1.19 or later
- Guest access enabled (Administration → Settings → Users). Only content visible to guests can be federated.
- The instance served over HTTPS on its public domain, with pretty URLs enabled so `/.well-known/webfinger` and `/.well-known/nodeinfo` reach HumHub
- A working queue / cron setup, because delivery and processing of inbound activities run as background jobs

## Console commands

```bash
php protected/yii activitypub/status                    # Show federation status
php protected/yii activitypub/ensure-instance-actor     # Create the instance actor if missing
php protected/yii activitypub/prune-remote-actors [1]   # Remove orphan federated users (dry run unless 1)
php protected/yii activitypub/reprocess-remote-posts    # Re-apply text/hashtag processing to ingested posts
php protected/yii activitypub/migrate-shadow-users      # Move legacy federated users onto the current identity model
```

## Repository

https://github.com/cuzy-app/activitypub

## Publisher

[CUZY.APP](https://www.cuzy.app/)

## Licence

[GNU AGPL](https://github.com/cuzy-app/activitypub/blob/main/docs/LICENCE.md)
