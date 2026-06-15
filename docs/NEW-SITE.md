# Creating a new UNDR event site

> **Moved.** The canonical, always-current onboarding guide now lives on the UNDR
> backend so it is one URL an AI agent (or a human) can be pointed at:
>
> - **Web:** <https://undr.zone/new>
> - **Raw Markdown:** <https://undr.zone/new.md> (or `curl -H 'Accept: text/markdown' https://undr.zone/new`)
> - **Public API reference:** <https://undr.zone/api>
>
> It covers the full recipe end to end: register the brand on the backend,
> scaffold the repo, consume `undr/core` via Composer, sync events **and** blog
> posts, and render the homepage, the `/buy-tickets/` page and the blog.

For deeper architecture and conventions (the merge model, the modal contract,
the `--undr-*` token system, what is and isn't shareable), see
[`SITE-DEVELOPMENT.md`](SITE-DEVELOPMENT.md) in this package.
