# CustomsBridge — AtoA Customs & TARIC Resolution Engine

[![Smithery](https://img.shields.io/badge/Smithery-Registry-blue)](https://smithery.ai)
[![Node](https://img.shields.io/badge/UNE_Node-04-emerald)](#)
[![Latency](https://img.shields.io/badge/Latency-Sub--3ms-green)](#)

High-throughput, deterministic customs classification, duty simulation, and import restriction engine built for autonomous procurement, freight forwarding, and cross-border trade agents.

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

### 3. `calculate_customs_duties`
Calculates official EU Common Customs Tariff (TDC/MFN) and import VAT (20%) on CIF value.
- **Parameters**:
  - `taric_code` (string, required): 6-digit HS code or 10-digit TARIC code.
  - `cif_value_eur` (number, required): Declared CIF customs value in EUR.
  - `origin_country` (string, optional): 2-letter ISO country code (default `CN`).

### 4. `check_import_restrictions`
Checks mandatory EU regulatory restrictions, required certificates (CE, RoHS, CBAM, REACH, CHED), and official document codes for SAD customs declaration (Box 44).
- **Parameters**:
  - `taric_code` (string, required): 6-digit HS or 10-digit TARIC code (e.g. `850440`, `7208`, `8541`).
  - `origin_country` (string, optional): 2-letter ISO origin country code (default `CN`).

## 🌐 Sino-European HS6 Pivot
The 6-digit Harmonized System (HS6) is universally shared between China's GACC export nomenclature and the European Union's TARIC system. CustomsBridge uses HS6 as a deterministic cross-border pivot to resolve European import tariffs directly from Chinese commercial proformas.

## 🛠️ Architecture
- **Engine**: Stateless PHP 8.1 CLI / JSON-RPC 2.0.
- **Database**: SQLite 3 with FTS5 unicode61 tokenizer.
- **Data Source**: European Commission DG TAXUD / TARIC consolidation.
- **State Proof**: SHA-256 sealed cryptographic payload with eIDAS Art. 41 timestamping.