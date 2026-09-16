# Review fixes: shared rules (plugin and backend must match)

Both `lameco\rankroute\services\text\TextImportValidator`/`HtmlSkeleton` (plugin) and
`App\Validation\RewriteValidator`/`HtmlSkeleton` (backend) implement these identically, with
shared test cases.

## R1. No template or env syntax in SEO values (`forbidden_syntax`)

SEOmatic renders `seo.*` through `Craft::parseEnv()` and object templates. For items whose id
starts with `seo.`: reject when the new value contains `{`, `}` or `$` and the original value
does not contain that same character, or when the new value starts with `@` and the original
does not.

## R2. Reference tags are immutable (`reference_tag_changed`)

For every item (plain and html, text nodes and attribute values alike): extract all matches of
`/\{[a-z][a-z0-9_\\]*:[^}]*\}/i` from the raw original and raw new value. The two lists, sorted,
must be identical byte for byte, fallback included (`{entry:6@1:url||https://x}` must stay
exactly that). So the model can neither add, remove nor alter a reference tag.

## R3. Strict fallback comparison on import

`HtmlSkeleton` gets a strict mode (default for import validation) in which the Craft reference
tag fallback is *not* normalised away. The relaxed normalisation stays only for the
canonical-vs-draft structure snapshot (Craft rewrites fallbacks on save).

## R4. Comments follow WHATWG and are part of the skeleton

The tokenizer ends a comment exactly as browsers do: `<!-->`, `<!--->`, `-->` and `--!>` all
close it. Each comment is a skeleton token including its full content, compared exactly. (Result:
the model cannot add, remove or change comments, and cannot smuggle tags through a comment
boundary.) Same treatment for `<![CDATA[ … ]]>`, `<!DOCTYPE …>` and `<? … >` bogus comments:
tokens compared exactly.

## R5. Raw-text elements

Content of `<script>`, `<style>`, `<textarea>`, `<title>`, `<xmp>`, `<iframe>`, `<noembed>`,
`<noframes>`, `<noscript>` is part of the skeleton token for that element and compared
exactly (it is not "text between tags").

## R6. Stricter plain values (`html_in_plain`)

A plain value is rejected when it contains `<` directly followed by a letter, `/`, `!` or `?`
(closing `>` not required).

## R7. Idempotent import (contract addition)

Import request gets optional `idempotencyKey`: string, 1-64 chars, `[A-Za-z0-9._:-]`, else 422
`invalid_idempotency_key`. The plugin stores it as the draft's notes, `rankroute:<key>`. When a
request arrives with a key and a draft of that element (same canonical id, same site) with those
notes already exists, the plugin does no validation against the fingerprint, writes nothing, re-runs
the structure check on that draft and returns the normal 200 response for it with
`"replayed": true` (and `changedItems` as stored: keep them in the draft notes as JSON after the key,
or recompute by diffing text items of canonical vs draft; pick one and document). The backend
sends `run-page-<id>` as key for every import, so retrying an import is safe.
