# Naming

## Decision (owner, 2026-10-25): `local_ltixsembed`

The owner renamed the plugin from `local_lticrossembed` to **`local_ltixsembed`** (install path `local/ltixsembed`), effective with release 0.2.0.
`xs` is the common abbreviation for cross-site; the name is shorter and stays family-consistent.

* Checked against the 4.5 and 5.3 sources: valid Frankenstyle name (lower-case letters and digits, starts with a letter, no
  underscore in the name part); no tables. The table-name limit is `xmldb_table::NAME_MAX_LENGTH = 63 - PREFIX_MAX_LENGTH(10) = 53`
  in both trees; the historical 28-character limit no longer applies to Moodle 4.5+ and is quoted below only as history.
* No upgrade path: Moodle cannot upgrade one component into another, so 0.2.0 is a fresh install; a 0.1.0 alpha site uninstalls
  `local_lticrossembed` first (see README, Installation, and CHANGELOG 0.2.0).
* Everything below this section is the earlier analysis (history), written while the name was provisional; its recommendation
  is superseded by this decision.

## History: earlier analysis

Rules checked: Frankenstyle `<type>_<name>`, lowercase letters and digits, name starts with a letter, no underscore inside the name for local plugins, total component length well under 100, table names (none here) would have to fit 28 characters including the prefix.

| Candidate | Length (name) | Pros | Cons |
| --- | --- | --- | --- |
| `local_lticrossembed` (current) | 14 | describes the cross-site embed concern, `local` fits a plugin without own activity or entity | "embed" may suggest mod_lti replacement |
| `local_ltiembedbridge` | 14 | names the postMessage bridge, the main mechanism | hides the fallback and audit |
| `local_ltiframesupport` | 15 | plain, covers iframe issues | vague, "support" says little |
| `tool_ltiembeddiagnostics` | 18 | audit and test pages are admin-tool-like | collides conceptually with tool_ltidiagnostics, and the hook injection is not a tool |
| `local_ltisessionfallback` | 17 | stresses the working fallback | too narrow, omits detection |

Recommendation: keep `local_lticrossembed`. It is the spec's name, passes all rules, and no alternative is clearly better. `tool_` is rejected because the plugin injects code into pages, which is not an admin tool task. The name stays provisional until the owner decides.
