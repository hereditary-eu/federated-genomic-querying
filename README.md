# Beacon-Like SPARQL Web App on HERO-Genomics

This project demonstrates a simple **PHP + Bootstrap** web application for issuing **Beacon-style SPARQL queries** against a **GraphDB** repository. It supports four query types:

1. **Beacon Sequence Query**  
2. **Beacon Range Query**  
3. **Beacon Bracket Query**  
4. **Beacon Aminoacid Change Query** (find genomic variants by a fixed `infoKey` = `"VT"` and a user-provided `infoValue`, e.g. `"INDEL"`)

After retrieving the main query results, the app automatically (after a short delay) fetches **metadata** for each row (except for the VT query, which skips metadata).

---

## Contents

- [Overview](#overview)
- [Files](#files)
  - [`index.php`](#indexphp)
  - [`ajax_handler.php`](#ajax_handlerphp)
- [Setup & Usage](#setup--usage)
- [Customization](#customization)
- [License](#license)

---

## Overview

This application is built in **PHP**. It uses the **Bootstrap** CSS framework for a cleaner UI and **AJAX** calls (via `fetch`) for running SPARQL queries on a [GraphDB](https://www.ontotext.com/products/graphdb/) repository. A **server-side cache** (in `$_SESSION`) prevents repeated queries from being re-run.

---

## Files

### `index.php`

- A single-page **Bootstrap** UI that:
  - Shows three placeholder logos at the top.
  - Offers a dropdown to select which query to run (Sequence, Range, Bracket, or VT).
  - Displays different parameter input fields depending on the selected query.
  - Uses an AJAX call (via the JavaScript `fetch` API) to query the backend (`ajax_handler.php`).
  - Shows a **spinner overlay** while waiting for the query results.
  - After the main query results appear, the system issues additional “metadata” queries for each result row (except for the VT query, which skips metadata).
  - Renders both the main query results and the metadata in **tables**.

### `ajax_handler.php`

- A **PHP** backend endpoint that:
  - Receives JSON POST requests from `index.php`.
  - Uses a **session-based** cache (in `$_SESSION`) to avoid re-running identical SPARQL queries.
  - Depending on the request’s `queryType`:
    - **`main`**: Runs one of four SPARQL queries (Sequence, Range, Bracket, or VT).
      - Each query is built with user-provided parameters.
      - Responds with a JSON array of rows (e.g. `{ "results": [ { "chrom": "1", "pos": "719853", ... }, ... ] }`).
    - **`metadata`**: Uses `(pos, ref, alt)` from a main query row to run a “metadata” SPARQL query, returning info keys/values (`infoKey`, `infoValue`).
  - Returns JSON responses with either `{"results": [...]}` or an `{"error": "..."}` message.

---

## Setup & Usage

1. **Install & Run GraphDB**  
   - Download and install [GraphDB](https://www.ontotext.com/products/graphdb/) on your local machine (or on a server).
   - Create a **repository** (e.g., named `myRepo`) and load your RDF data into it.

2. **Enable Anonymous Read** (optional)  
   - If you want the app to query without credentials, ensure your GraphDB repository allows **anonymous read** access.
   - Otherwise, provide credentials in `ajax_handler.php` using `curl_setopt($ch, CURLOPT_USERPWD, "user:pass");`.

3. **Adjust Configuration**  
   - In `ajax_handler.php`, locate the line:  
     ```php
     $graphdbEndpoint = "http://localhost:7200/repositories/YOUR_REPO_HERE";
     ```
     Replace `YOUR_REPO_HERE` with your actual repository name (for example, `myRepo`).

4. **Place files on a PHP server**  
   - Put `index.php` and `ajax_handler.php` in the same folder on your web server.
   - Make sure session support is active (e.g., call `session_start()` and check `php.ini`).

5. **Open `index.php` in your Browser**  
   - For example, `http://localhost/path/to/index.php`.
   - Select a query type (Sequence, Range, Bracket, or VT).
   - Fill in the parameters (position, reference, etc.).
   - Click **Run Main Query**. The spinner appears.
   - Once the main query completes, results are displayed. If applicable (for queries 1–3), a second metadata query runs automatically (after half a second) to retrieve additional info.

---

## Customization

- **SPARQL Query IRIs**:  
  If your data uses different ontology IRIs or properties, update the `PREFIX` lines or the triple patterns in `ajax_handler.php`.
- **Literal Types**:  
  If your data uses `xsd:string` instead of `rdf:PlainLiteral`, adjust the `FILTER` conditions to match.
- **Caching**:  
  By default, caching is in `$_SESSION`. For multi-user production scenarios, consider using a more robust shared cache (files, Redis, etc.).
- **Spinner / Layout**:  
  Modify or remove the spinner overlay or card styles in `index.php`.
- **Authentication**:  
  - If GraphDB is secured, add Basic Auth with `curl_setopt($ch, CURLOPT_USERPWD, "username:password");`.
  - Limit public access as needed.

---
