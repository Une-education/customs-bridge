# CustomsBridge — AtoA Customs & TARIC Resolution Engine

[![Smithery](https://img.shields.io/badge/Smithery-Registry-blue)](https://smithery.ai)
[![Node](https://img.shields.io/badge/UNE_Node-04-emerald)](#)
[![Latency](https://img.shields.io/badge/Latency-Sub--3ms-green)](#)

High-throughput, deterministic customs classification and nomenclature resolution engine built for autonomous procurement, freight forwarding, and cross-border trade agents.

Operated under the **Unified Norms Engine (UNE)** infrastructure.

## 🚀 Live Endpoints

- **Live Hub**: https://customs.une.education
- **MCP Endpoint**: `https://customs.une.education/mcp.php`
- **OpenAPI 3.1 Spec**: `https://customs.une.education/openapi.json`
- **Agent Specification**: `https://customs.une.education/llms.txt`

## 📦 Capabilities & MCP Tools

### 1. `search_customs_nomenclature`
Performs BM25 sub-5ms full-text search across 25,800+ official statutory commodity descriptions (English & French).
- **Parameters**:
  - `query` (string, required): Commodity description or keyword (e.g. `electric motors`, `accumulateurs lithium`, `drones`).
  - `limit` (integer, optional): Maximum results (1-50, default 5).

### 2. `resolve_hs_code`
Traverses the customs tariff tree starting from an international 6-digit Harmonized System (HS6) pivot code or exact TARIC 10-digit code prefix.
- **Parameters**:
  - `code` (string, required): 2 to 10-digit code prefix (e.g. `850440` for static converters/inverters).

## 🌐 Sino-European HS6 Pivot
The 6-digit Harmonized System (HS6) is universally shared between China's GACC export nomenclature and the European Union's TARIC system. CustomsBridge uses HS6 as a deterministic cross-border pivot to resolve European import tariffs directly from Chinese commercial proformas.

## 🛠️ Architecture
- **Engine**: Stateless PHP 8.1 CLI / JSON-RPC 2.0.
- **Database**: SQLite 3 with FTS5 unicode61 tokenizer.
- **Data Source**: European Commission DG TAXUD / TARIC consolidation.
