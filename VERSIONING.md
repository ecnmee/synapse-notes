# Versioning convention

Articles in this repo change over time. The rule below exists so a reader
who saved or cited an older version can always find exactly what changed
and when, instead of silently seeing different content under the same URL.

## 1. Every article file carries a metadata header

```yaml
---
type: feat        # feat | fix | doc | refactor (same vocabulary as commit prefixes)
version: v0.3
date: 2026-06-19
supersedes: none  # or a link/tag to the previous version, see below
---
```

- `type` follows the same prefixes used in commit messages for this repo
  (`feat`, `fix`, `doc`, `refactor`). It describes the nature of the
  change that produced *this* version, not the article's topic.
- `version` is bumped on every substantive change (a typo fix does not
  count; a changed claim, a new section, or a corrected technical detail
  does).
- `supersedes` points to the git tag of the version being replaced. Left
  as `none` only for the first published version of an article.

## 2. Every substantive update gets a git tag

```bash
git tag -a articles/cortex-memory-architecture/v0.3 -m "feat: add bilingual EN version and references"
git push origin articles/cortex-memory-architecture/v0.3
```

Tag naming: `articles/<article-folder>/<version>`.

## 3. Updating an article

1. Edit the file(s).
2. Bump `version` in the frontmatter.
3. Set `supersedes` to the tag of the version just replaced.
4. Add an entry to the article's `CHANGELOG.md` (what changed, why, and
   which tag it supersedes).
5. Commit with a prefixed message (`feat:`, `fix:`, `doc:`...).
6. Tag the commit as in step 2 above.

This way, if the idea evolves or a claim turns out to be wrong, the old
version stays reachable at its tag forever, the new version explicitly
says what it replaces, and nothing has to be retroactively rewritten or
silently swapped.
