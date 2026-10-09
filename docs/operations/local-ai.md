# Local AI Operations

How to run the optional local AI runtime that powers AI explanations
(Phase 29, [Local AI Intelligence](../architecture/ai-intelligence-v1.md))
and the AI assessment ([AI Assessment](../architecture/ai-assessment-v1.md)).

AI is **optional**. With `AI_ENABLED=false` (the default) every CodeDNA
result, including scores, gaps, roadmaps, challenges and growth, is complete,
and the pages say that AI explanations are not enabled. Nothing in the
deterministic pipeline waits for or depends on a model.

## Contents

- [Overview](#overview)
- [Quick start (development)](#quick-start-development)
- [Choosing a model](#choosing-a-model)
- [GPU](#gpu)
- [Production](#production)
- [Health](#health)
- [Evaluation](#evaluation)
- [Trust boundary](#trust-boundary)
- [Tuning](#tuning)
- [Troubleshooting](#troubleshooting)

## Overview

```text
browser ── API (backend) ── queue "assessment" ── ai-worker ── http://ollama:11434 (private network)
                                                                    │
                                                         ollama_models volume
```

- `ollama`: the [Ollama](https://ollama.com) runtime, image pinned to
  `ollama/ollama:0.12.6`, in the Compose profile `local-ai`. It is never
  started unless you ask for that profile, publishes no port, and keeps
  models in the `ollama_models` volume.
- `ai-worker`: a queue worker that consumes only the `assessment` queue
  (AI assessments and insights). The main `queue` worker consumes
  `analysis,challenge,github`, so a slow generation never delays analysis.
- CodeDNA never downloads a model. You pull the model you choose, once.

## Quick start (development)

```bash
# 1. Start the runtime (CPU) next to the usual stack.
docker compose --profile local-ai up -d ollama

# 2. Pull a model explicitly (about 4.7 GB for the default).
docker compose exec ollama ollama pull qwen2.5-coder:7b

# 3. Enable AI in .env, then recreate the PHP services.
#    AI_ENABLED=true
#    AI_PROVIDER=ollama
#    AI_MODEL=qwen2.5-coder:7b
#    AI_BASE_URL=http://ollama:11434
docker compose up -d backend queue ai-worker scheduler

# 4. Check it (levels 1–3 below).
docker compose exec backend php artisan ai:status
docker compose exec backend php artisan ai:smoke
```

Then open a compared growth snapshot, an active learning roadmap or an
evaluated challenge attempt and choose **Explain with local AI**.

For automated tests and UI work without a model, use `AI_PROVIDER=fake`
(deterministic templates; refused in production).

## Choosing a model

The model must fit your hardware **including its context window**
(`AI_CONTEXT_TOKENS`, 8192 by default), not only its weights. Sizes below
are approximate for the default 4-bit quantizations Ollama serves; check the
real footprint with `docker compose exec ollama ollama ps` after a first
request.

| Model tag | Download | Good for | Notes |
| --- | --- | --- | --- |
| `qwen2.5-coder:7b` (default) | ~4.7 GB | GPUs with 6–8 GB VRAM, or CPU with 16 GB RAM | Best fit for a 6 GB card (for example an RTX 3060 6 GB) with an 8k context. If it spills to the CPU, lower `AI_CONTEXT_TOKENS` to 4096 |
| `qwen2.5-coder:3b` | ~1.9 GB | 4 GB VRAM, or CPU-only machines | Faster; explanations are rejected by the validator more often |
| `qwen2.5-coder:1.5b`, `qwen2.5:0.5b` | ~1 GB, ~0.4 GB | Smoke tests only | They answer, but often fail the evidence rules (they invent changes) |
| `qwen2.5-coder:14b` | ~9 GB | 12 GB+ VRAM | Better adherence; does not fit 6 GB VRAM without heavy CPU offload |
| `qwen3-coder:30b` | ~19 GB | 24 GB VRAM, or ≥32 GB system RAM with CPU offload | A candidate, not a requirement. On a 6 GB GPU most layers run on the CPU, so expect minutes per explanation and set `AI_TIMEOUT_SECONDS` and `AI_JOB_TIMEOUT_SECONDS` accordingly |

Any model that supports Ollama's structured output works; set `AI_MODEL` to
its exact tag. A tag without a suffix means `:latest`. Changing the model
does not affect stored explanations: new requests are generated with the new
model, and queued ones generated with a different configuration fail with
`configuration_changed` instead of mixing models.

Smaller models fail validation more often. That is expected: CodeDNA shows
"No explanation is available" rather than an explanation it cannot check
against the evidence.

## GPU

CPU is the default and gives the same results, more slowly. For an NVIDIA GPU:

1. Install the NVIDIA driver and the
   [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html)
   on the host, and confirm `docker run --rm --gpus all ubuntu nvidia-smi`
   works.
2. Start Ollama with the overlay:

   ```bash
   docker compose -f docker-compose.yml -f docker/ollama/compose.gpu.yml --profile local-ai up -d ollama
   ```

3. Confirm the model is on the GPU after one request:
   `docker compose exec ollama ollama ps` (the PROCESSOR column shows
   `100% GPU`, or a CPU/GPU split when the model does not fit).

With 6 GB of VRAM, keep `OLLAMA_MAX_LOADED_MODELS=1` and
`OLLAMA_NUM_PARALLEL=1` (the defaults) and `AI_MAX_CONCURRENCY=1`: parallel
requests multiply the context memory.

The same overlay works with `docker-compose.prod.yml`.

## Production

`docker-compose.prod.yml` defines the same two services, hardened: Ollama is
read-only with a tmpfs `/tmp`, all capabilities dropped,
`no-new-privileges`, memory and CPU limits (`OLLAMA_MEM_LIMIT`, default
`12g`; `OLLAMA_CPUS`, default `4`) and no published port. It sits on the
internal `ai` network with the backend and `ai-worker`, plus `egress` so
that you can pull models; nothing else reaches it.

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production --profile local-ai up -d ollama
docker compose -f docker-compose.prod.yml --env-file .env.production exec ollama ollama pull qwen2.5-coder:7b
# Set AI_ENABLED=true and the AI_* values in .env.production, then:
docker compose -f docker-compose.prod.yml --env-file .env.production up -d backend queue ai-worker scheduler
docker compose -f docker-compose.prod.yml --env-file .env.production exec backend php artisan ai:status
```

`make prod-config` renders the production file with the `local-ai` profile
so its checks cover these services. If you do not use local AI, leave the
profile off: `ai-worker` still runs and simply finds no work.

## Health

AI health has four levels. Only the first two are checked automatically,
and none of them affects `/api/v1/health` or container health of the
application: a missing model never makes CodeDNA unhealthy.

| Level | Meaning | How to check |
| --- | --- | --- |
| 1. Runtime reachable | The runtime answers (`/api/version`) | `php artisan ai:status`, `GET /api/v1/ai/status` (`reachable`) |
| 2. Model available | The configured tag is pulled (`/api/tags`) | `php artisan ai:status`, `GET /api/v1/ai/status` (`model_available`) |
| 3. Minimal generation | The model returns schema-valid JSON | `php artisan ai:smoke` (on demand; one tiny generation) |
| 4. Explanations pass validation | Real insights are accepted by the evidence rules | Failed insights and assessments: `failure_code` `INVALID_OUTPUT` with the rule in `failure_detail`, and the `insight.failed` log line's `reason` |

Levels 1 and 2 are cached for `AI_HEALTH_CACHE_SECONDS` (60 s). Users can
request an explanation only while both pass; otherwise the request is
refused with `AI_UNAVAILABLE` and the page says the service is not
available. `php artisan ai:status --json` prints the same report for
monitoring, including in-flight generations, pending jobs and per-outcome
counts of model calls (`SUCCEEDED`, `PROVIDER_TIMEOUT`, `SLOTS_BUSY`…). A
call counts as `SUCCEEDED` when the runtime answered; whether the answer
then passed the evidence rules is level 4. With `AI_ENABLED=false` the
command contacts nothing, reports the checks as not checked and exits 0;
otherwise it exits 1 when level 1 or 2 fails.

`GET /api/v1/ai/status` returns only `enabled`, `available`, provider name,
endpoint kind (`local`, `remote`, `fake`), model tag and the two health
flags: never a URL, key or error body.

## Evaluation

`backend/resources/ai-eval/insight-eval-v1.json` is a small, versioned
evaluation set (Phase 30). Each case is fixed evidence for one scenario
(strong, insufficient, conflicting evidence; no growth and genuine growth;
failed and passed challenges; locked and available roadmap steps; prompt
injection; malformed answers, markup and score fields), a fixed model answer and the
validator decision that answer must get. Two separate questions are
measured:

- **Is the validator correct?** `tests/Unit/Insights/InsightEvalSetTest.php`
  runs every fixed answer through the validator and requires exactly the
  expected decision. It runs in every test suite and needs no model.
- **Is the model useful?** `php artisan ai:eval` sends each case's evidence
  to the configured model through the gateway, exactly as an insight would
  be, validates the answer and prints the outcome, the rejection rule, the
  duration and the token counts (`--json` for a machine-readable report,
  `--case=<id>` to run a subset). Nothing is stored and no quota is used.

```bash
docker compose exec backend php artisan ai:eval
docker compose exec backend php artisan ai:eval --json > ai-eval-$(date +%F).json
```

A rejection is the validator doing its job: the answer could not be checked
against the evidence and would not have been shown. Many rejections mean the
model is not useful enough for user-facing explanations, not that anything
is unsafe. Compare models by acceptance rate and by reading the accepted
answers against the evidence; record the hardware, model tag and
quantization with each run.

Known limitation: a single sentence that names an improved and an unchanged
metric together is rejected, because a direction word must match every
observation the sentence cites (case `mixed-sentence-known-strictness`).

## Trust boundary

The evidence sent to the model is derived from private repositories. The
model endpoint is therefore part of the installation's trust boundary:

- The default endpoint is `http://ollama:11434` on the private Docker
  network. Plain http is accepted only for a single-label internal host.
- In production, a host with a dot or an IP address is treated as remote:
  it needs `https` **and** `AI_ALLOW_REMOTE_ENDPOINT=true`, or the
  application refuses to start. Use this only for a model server you
  operate and trust (for example, a GPU host on your own network).
- There is no fallback: if the configured endpoint fails, the explanation
  fails. CodeDNA never sends evidence to any other provider.
- `AI_API_KEY` is optional (Ollama needs none), never logged, stored or
  returned by the API.
- What is and is not sent is listed in
  [Evidence](../architecture/ai-intelligence-v1.md#evidence). Source code,
  file paths, challenge test values and user text are never sent.
- Model output is untrusted. It is validated against the evidence and
  rendered as plain text only.

## Tuning

| Symptom | Setting |
| --- | --- |
| Generations time out on CPU | Raise `AI_TIMEOUT_SECONDS` (and `AI_JOB_TIMEOUT_SECONDS` above timeout + slot wait), or use a smaller model / the GPU overlay |
| `INPUT_TOO_LARGE` | Raise `AI_CONTEXT_TOKENS` if memory allows (more VRAM/RAM), or keep it and accept that very large comparisons are not explained |
| Jobs often wait for a slot | Expected with `AI_MAX_CONCURRENCY=1`; raise it only if the hardware runs several generations at once (and `OLLAMA_NUM_PARALLEL` with it) |
| The first request after a pause is slow | The model is loaded on demand; raise `AI_KEEP_ALIVE` (for example `30m`) to keep it in memory |
| Memory pressure on the host | Lower `OLLAMA_MEM_LIMIT`, use a smaller model, or lower `AI_KEEP_ALIVE` |

## Troubleshooting

```bash
docker compose ps ollama ai-worker                       # both running?
docker compose exec backend php artisan ai:status        # levels 1–2 and counters
docker compose exec backend php artisan ai:smoke         # level 3
docker compose exec ollama ollama list                   # pulled models
docker compose exec ollama ollama ps                     # loaded model, CPU/GPU split
docker compose logs --tail=100 ai-worker                 # job errors
docker compose logs --tail=100 backend | grep ai.completion
```

| Problem | Cause and fix |
| --- | --- |
| Page says AI is not enabled | `AI_ENABLED=false`; set it and recreate `backend`, `queue`, `ai-worker` and `scheduler` |
| "Not available right now" | Level 1 or 2 fails: start the `local-ai` profile, check `AI_BASE_URL`, pull `AI_MODEL` exactly as written |
| `ai:status` shows "Model available: NO" | The tag is not pulled, or differs (for example `:latest` versus a size tag); pull `AI_MODEL` exactly |
| Explanations stay "Waiting" | `ai-worker` is not running, or it is processing a long generation; see its logs |
| `PROVIDER_TIMEOUT` | CPU generation slower than `AI_TIMEOUT_SECONDS`; see [Tuning](#tuning) |
| Many `INVALID_OUTPUT` | The model does not follow the evidence rules well enough; use a larger model. Reason codes are in the `ai.completion` log and the insight metadata |
| Application refuses to start after an upgrade | A remote `AI_BASE_URL` now needs `AI_ALLOW_REMOTE_ENDPOINT=true` (and https) in production |
| GPU not used | The overlay is missing, or the NVIDIA Container Toolkit is not installed; see [GPU](#gpu) |
