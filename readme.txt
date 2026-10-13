=== Relecture ===
Contributors: bendeveloperpro
Tags: proofreading, editorial, review, annotation, export
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Proofread, correct and annotate posts and pages in a three-column table, then export the review to Excel, CSV, Word, Markdown or JSON.

== Description ==

Relecture (French for “proofreading”) shows the title and every text block of a post or a page in a simple table:

* **Original text**: what the content says today, block by block (headings, paragraphs, list items, quotes, captions, alternative texts, table cells…);
* **Correction**: the text as it should read;
* **Note**: a remark on this content, or a **guideline for future content**.

Everything saves as you type. The content itself is never modified: a review is a separate snapshot, so you can share it, discuss it and apply it when you decide to.

= Hand the review over =

* **Excel (.xlsx), CSV and Word (.docx)** for a proofreader, a client or a colleague. The Word document is an A4 landscape table, ready to print.
* **Markdown and JSON** for an AI assistant or a script: each row carries the block name and its position in the content, so the text can be found and corrected at its source.
* **REST API** (`relecture/v1`) with application passwords, and **WP-CLI** commands (`wp relecture export`, `wp relecture set-item-status`…) for automated workflows.

= Grow your editorial guidelines =

Tick “Guideline for future content” under a note: it joins the **Guidelines** page, which gathers them across all your reviews. Export them as Markdown to feed the instructions of your next articles, then mark them as integrated.

= Workflow =

* Each correction has a status: to validate, accepted, rejected, applied.
* A review goes from “in progress” to “review complete”, then “corrections applied” (read-only). A new round can then start; previous rounds stay available.
* If the content changes after a review started, Relecture tells you and can take a new snapshot while keeping every correction and note attached to its text.

= Privacy and security =

* Relecture makes **no external request** and loads nothing from third-party servers.
* Only users who can edit a content can review it; every screen, export and API route checks it.
* Exports are generated on the fly and never stored in the uploads folder. CSV cells that a spreadsheet could run as formulas are neutralized.
* Reviews store the ID of the users who wrote them.

= Development =

The source code, development environment and documentation live on GitHub: https://github.com/bendeveloperpro/relecture. The JavaScript and CSS files are shipped unminified: there is no build step.

== Installation ==

1. Upload the `relecture` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Open **Relecture** in the admin menu, or use the “Review” link under a post in the Posts and Pages lists.

== Frequently Asked Questions ==

= Does Relecture change my posts? =

No. It stores its own snapshot of the text, with the corrections and notes, in two dedicated database tables. Applying the corrections stays your decision (or that of the person or the AI you hand the export to).

= Who can review? =

Any user who can edit the content: administrators and editors for every content, authors for their own. Developers can change this with the `relecture_can_review` filter.

= Which content can be reviewed? =

Posts, pages, synced patterns and public custom post types that use the editor, written with the block editor or the classic editor. Text that page builders store outside the post content is not read; developers can add rows with the `relecture_post_segments` and `relecture_block_segments` filters.

= Why does a CSV cell start with an apostrophe? =

A cell starting with =, +, - or @ could be run as a formula by a spreadsheet (“CSV injection”). Relecture prefixes it with an apostrophe. The Excel export does not need this: its cells are always text.

= Is my data deleted when I uninstall the plugin? =

Yes, the plugin's tables and options are removed on every site. To keep them, add `define( 'RELECTURE_KEEP_DATA', true );` to wp-config.php before uninstalling.

== Screenshots ==

1. The review table: original text, correction with its status, and a note that can become a guideline for future content.
2. Contents to review, with the state of each review.
3. Guidelines gathered across reviews, ready to export as Markdown for your next articles.
4. A review exported to Word: an A4 landscape table, ready for a proofreader.
5. On a phone, each row of the table becomes a card.

== Changelog ==

= 0.1.0 =
* First version: three-column review table with autosave, correction statuses, guidelines, review rounds, snapshot sync, exports (Excel, CSV, Word, Markdown, JSON), REST API and WP-CLI commands. French translation included.
