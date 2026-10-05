# Product Vision

## What CodeDNA is

CodeDNA is a **developer intelligence and growth platform**. It analyzes a
developer's real source code, and over time their repositories, practice and
history, to build a continuously evolving **Developer DNA**: an
evidence-backed profile of how they write code, where they are strong, where
they have gaps, and how they are improving.

CodeDNA is **not** a linter or a generic code-quality gate. A linter judges a
codebase against rules. CodeDNA describes a *developer* and tracks their
growth.

## Questions CodeDNA answers

- How does this developer write code? Which patterns do they use
  consistently?
- What are their strongest technical areas, and their weaknesses?
- Which skills are missing, and what should they learn next?
- Has their competency improved after learning and practice?
- How do they compare with **their own** history?
- Later, for teams: how is an engineering team's competency evolving?

## The growth loop

```text
ASSESS ─► ANALYZE ─► IDENTIFY GAPS ─► LEARN ─► PRACTICE ─► RE-ASSESS ─► UPDATE DNA ─┐
   ▲                                                                                │
   └────────────────────────────────────────────────────────────────────────────────┘
```

## Product principles

1. **Evidence over opinion.** Every score is traceable to deterministic
   measurements of real code. Self-declared skills are never treated as
   evidence of expertise.
2. **Deterministic core, AI on top.** Static analysis produces metrics and
   scores. AI only *interprets* them, by explaining, summarizing and
   recommending. It never invents numbers.
3. **Honest about uncertainty.** When evidence is insufficient, CodeDNA says
   so instead of showing a made-up score.
4. **Versioned and reproducible.** The same code under the same engine
   version always yields the same result, and old results stay
   interpretable after the engine improves.
5. **Source code is sensitive.** Code is never executed during analysis. It
   is stored privately, and secrets are never logged or sent to AI
   providers.
6. **Self-comparison first.** Growth is measured against the developer's own
   history before any comparison with other people.

## Target users

| Stage | User | Value |
|---|---|---|
| MVP | Individual developers | Objective profile of their code, strengths and weaknesses |
| Growth | Developers investing in learning | Skill gaps, roadmaps, challenges, measured progress |
| B2B (later) | Engineering leads and organizations | Team competency, team DNA, growth trends |

## Long-term capability map

The full ecosystem is built incrementally (see the
[phase plan](../architecture/overview.md#delivery-phases)):

developer profile, projects, repository integration (GitHub, GitLab,
Bitbucket), source code analysis, AST analysis, static metrics, Developer
DNA, competency matrix, skill gap analysis, AI assessment and interpretation,
coding challenges, learning roadmaps, growth tracking, historical DNA, team
analytics and team DNA, engineering intelligence, SaaS billing, and
enterprise/self-hosted deployment.

## Competency model (later phases)

- Competencies such as Backend, PHP, Laravel, Database, Testing,
  Architecture, DevOps, Security, Frontend, Git and System Design.
- Levels: `BEGINNER`, `INTERMEDIATE`, `ADVANCED`, `EXPERT`.
- Every level is **backed by evidence**: code analysis, repository history,
  challenges, assessments and project complexity.
- **Skill gap** = target competency − current competency, prioritized, and
  feeding a learning roadmap of objectives, practice, a challenge and
  re-assessment.

## Non-goals (for now)

- Ranking or hiring decisions about individuals based on CodeDNA scores.
  Scores describe code evidence and are not a verdict on a person.
- Executing user code. That would need a dedicated sandbox, introduced only
  with coding challenges, and with strict isolation.
- Team and organization features before the individual product works.
