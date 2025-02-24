<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Beacon SPARQL Queries + Metadata</title>
  <!-- Bootstrap CSS -->
  <link 
    rel="stylesheet" 
    href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"
  >
  <style>
    /* Just to position the logos side by side */
    .logo-container {
      display: flex;
      justify-content: space-around;
      margin-bottom: 1rem;
    }
    .logo-container img {
      max-width: 60px;
      height: auto;
    }

    /* Spinner overlay */
    #spinner-overlay {
      position: fixed;
      display: none; /* hidden by default */
      top: 0; left: 0; right: 0; bottom: 0;
      background-color: rgba(255,255,255,0.8);
      z-index: 9999;
      align-items: center;
      justify-content: center;
    }
  </style>
  <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
  <link rel="shortcut icon" href="/favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
  <link rel="manifest" href="/site.webmanifest" />
</head>
<body class="bg-light">

<div class="container mt-4">
  <!-- TOP LOGOS -->
  <div class="logo-container">
    <img src="1.png" alt="Logo1">
    <img src="2.png" alt="Logo2">
    <img src="3.png" alt="Logo3">
  </div>

  <div class="card">
    <div class="card-header">
      <h3>Beacon SPARQL Queries + Metadata</h3>
    </div>
    <div class="card-body">
      <div class="alert alert-danger d-none" id="error-msg"></div>

      <!-- Query Selector -->
      <div class="form-group">
        <label for="queryType">Select Query Type</label>
        <select class="form-control" id="queryType">
          <option value="">-- choose --</option>
          <option value="1">Beacon Sequence Query</option>
          <option value="2">Beacon Range Query</option>
          <option value="3">Beacon Bracket Query</option>
          <option value="4">Beacon Aminoacid Change Query</option>
        </select>
      </div>

      <!-- Sequence Query fields -->
      <div id="params-sequence" class="query-params" style="display:none;">
        <h5>Sequence Query Parameters</h5>
        <div class="form-row">
          <div class="form-group col-md-3">
            <label>Chromosome</label>
            <input type="text" class="form-control" id="chromSeq" value="1">
          </div>
          <div class="form-group col-md-3">
            <label>Position</label>
            <input type="text" class="form-control" id="posSeq" value="719854">
          </div>
          <div class="form-group col-md-3">
            <label>Reference Bases</label>
            <input type="text" class="form-control" id="refSeq" value="CAG">
          </div>
          <div class="form-group col-md-3">
            <label>Alternate Bases</label>
            <input type="text" class="form-control" id="altSeq" value="C">
          </div>
        </div>
      </div>

      <!-- Range Query fields -->
      <div id="params-range" class="query-params" style="display:none;">
        <h5>Range Query Parameters</h5>
        <div class="form-row">
          <div class="form-group col-md-4">
            <label>Chromosome</label>
            <input type="text" class="form-control" id="chromRange" value="1">
          </div>
          <div class="form-group col-md-4">
            <label>Start</label>
            <input type="text" class="form-control" id="startRange" value="719853">
          </div>
          <div class="form-group col-md-4">
            <label>End</label>
            <input type="text" class="form-control" id="endRange" value="719854">
          </div>
        </div>
      </div>

      <!-- Bracket Query fields -->
      <div id="params-bracket" class="query-params" style="display:none;">
        <h5>Bracket Query Parameters</h5>
        <div class="form-row">
          <div class="form-group col-md-2">
            <label>Chromosome</label>
            <input type="text" class="form-control" id="chromBrac" value="1">
          </div>
          <div class="form-group col-md-2">
            <label>Start Min</label>
            <input type="text" class="form-control" id="startMin" value="719853">
          </div>
          <div class="form-group col-md-2">
            <label>Start Max</label>
            <input type="text" class="form-control" id="startMax" value="719890">
          </div>
          <div class="form-group col-md-2">
            <label>End Min</label>
            <input type="text" class="form-control" id="endMin" value="719854">
          </div>
          <div class="form-group col-md-2">
            <label>End Max</label>
            <input type="text" class="form-control" id="endMax" value="719854">
          </div>
        </div>
      </div>

      <!-- VT/INDEL Query fields -->
      <div id="params-vt" class="query-params" style="display:none;">
        <h5>VT Query Parameters</h5>
        <p>
          <strong>Note:</strong> here you set the variant type parameter only.
        </p>
        <div class="form-group">
          <label>Variant Type</label>
          <input type="text" class="form-control" id="infoValueVt" value="INDEL">
        </div>
      </div>

      <button id="runQueryBtn" class="btn btn-primary">Run Main Query</button>
      <hr/>

      <div id="mainResults"></div>
      <div id="metadataResults"></div>
    </div>
  </div>
</div>

<!-- Spinner overlay -->
<div id="spinner-overlay" class="">
  <div class="d-none"></div> <!-- minimal safeguard -->
</div>

<script>
/* 
  1) Global dictionary to hold base64 logos keyed by organization "id". 
     We'll load it below in loadOrganizations().
*/
let orgLogos = {};

// 2) Load organizations on page load
async function loadOrganizations() {
  try {
    const response = await fetch('https://beacon-network.org/api/organizations');
    if (!response.ok) throw new Error('HTTP Error ' + response.status);

    const data = await response.json();
    data.forEach(org => {
      // org.id -> base64
      orgLogos[org.name] = org.logo;
    });
  } catch(err) {
    console.error('Failed to load organizations:', err);
  }
}

// We'll trigger the fetch for logos as soon as DOM is ready
window.addEventListener('DOMContentLoaded', loadOrganizations);


// Initially hide spinner
document.getElementById('spinner-overlay').style.display = 'none';

function updateParamVisibility() {
  const val = document.getElementById('queryType').value;
  document.getElementById('params-sequence').style.display = (val === '1') ? 'block' : 'none';
  document.getElementById('params-range').style.display    = (val === '2') ? 'block' : 'none';
  document.getElementById('params-bracket').style.display  = (val === '3') ? 'block' : 'none';
  document.getElementById('params-vt').style.display       = (val === '4') ? 'block' : 'none';
}
document.getElementById('queryType').addEventListener('change', updateParamVisibility);
updateParamVisibility(); // on page load

function showSpinner() {
  const overlay = document.getElementById('spinner-overlay');
  overlay.innerHTML = `
    <div class="d-flex" style="width:100%; height:100%; align-items:center; justify-content:center;">
      <div class="spinner-border text-primary" role="status">
        <span class="sr-only">Loading...</span>
      </div>
    </div>
  `;
  overlay.style.display = 'block';
}

function hideSpinner() {
  const overlay = document.getElementById('spinner-overlay');
  overlay.style.display = 'none';
  overlay.innerHTML = '';
}

function showError(msg) {
  const errDiv = document.getElementById('error-msg');
  errDiv.innerText = msg;
  errDiv.classList.remove('d-none');
}

// MAIN "Run Query" event
document.getElementById('runQueryBtn').addEventListener('click', async () => {
  // Clear UI
  document.getElementById('error-msg').classList.add('d-none');
  document.getElementById('mainResults').innerHTML = '';
  document.getElementById('metadataResults').innerHTML = '';

  const beaconType = document.getElementById('queryType').value;
  if(!beaconType) {
    alert('Please select a query type.');
    return;
  }

  // Gather parameters based on type
  let payload = { queryType: 'main', beaconType }; 

  if (beaconType === '1') {
    // Sequence
    payload.chrom = document.getElementById('chromSeq').value;
    payload.pos = document.getElementById('posSeq').value;
    payload.ref = document.getElementById('refSeq').value;
    payload.alt = document.getElementById('altSeq').value;
  } 
  else if (beaconType === '2') {
    // Range
    payload.chrom = document.getElementById('chromRange').value;
    payload.start = document.getElementById('startRange').value;
    payload.end   = document.getElementById('endRange').value;
  }
  else if (beaconType === '3') {
    // Bracket
    payload.chrom    = document.getElementById('chromBrac').value;
    payload.startMin = document.getElementById('startMin').value;
    payload.startMax = document.getElementById('startMax').value;
    payload.endMin   = document.getElementById('endMin').value;
    payload.endMax   = document.getElementById('endMax').value;
  }
  else if (beaconType === '4') {
    // The new "VT Query" (infoKey="VT" is constant, user picks infoValue)
    payload.infoValue = document.getElementById('infoValueVt').value;
  }

  try {
    showSpinner();
    const response = await fetch('ajax_handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    if(!response.ok) {
      throw new Error('HTTP error ' + response.status);
    }
    const data = await response.json();
    hideSpinner();

    if(data.error) {
      showError(data.error);
      return;
    }
    if(!data.results || data.results.length === 0) {
      document.getElementById('mainResults').innerHTML =
        `<div class="alert alert-info">No main results found.</div>`;
      return;
    }

    // Display main results
    displayMainResults(data.results);

    // If it's the new query type 4, skip metadata
    if(beaconType === '4') {
      return; 
    }

    // Otherwise, after half a second, run metadata queries
    setTimeout(() => {
      runMetadataQueries(data.results);
    }, 500);

  } catch(err) {
    hideSpinner();
    showError(err.message);
  }
});

// Render main results in a table
function displayMainResults(rows) {

  const container = document.getElementById('mainResults');
  let html = `<h5>Main Query Results</h5>`;

  // Build table
  html += `<table class="table table-bordered table-sm"><thead><tr>`;
  const headers = Object.keys(rows[0]);
  headers.forEach(h => {
    if (h !== "metadata") {
      html += `<th>${escapeHtml(h)}</th>`;
    }
  });
  html += `</tr></thead><tbody>`;

  rows.forEach((r) => {
    html += `<tr>`;
    headers.forEach(h => {
      let string = escapeHtml(r[h]);
      
      // We specifically want to inject logos if h == "dataset" (or h == 0).
      if (h === "dataset" || h === 0) {
        // Check for special cases
        if (r[h] === "cineca") {
          string = `<img src="CINECA_logo.png" style="width:3.5em;">`;
        }
        else if (r[h] === "1000geno") {
          string = `<img src="1000genomes.png" style="width:3.5em;">`;
        }
        else {
          const splittedText = r[h].split("-");
          let beaconName = splittedText[splittedText.length - 1].trim();
          // Check if we have a base64 logo for this ID
          const base64logo = orgLogos[beaconName];  // orgLogos is our global dictionary

          if (base64logo) {
            // Show both the default beacon logo + the org’s base64 logo
            string = `
            <img src="data:image/png;base64,${base64logo}" style="width:3em; margin-right:0.5em;" alt="${escapeHtml(r[h])} Logo" />
            <img src="beacon_logo.png" style="width:2em; margin-right:0.5em;" alt="Beacon Logo"/>
              ${r[h]}
            `;
          } else {
            // No base64 found => fallback to your existing default
            string = `
              <img src="beacon_logo.png" style="width:2em;" alt="Beacon Logo"/>
              ${r[h]}
            `;
          }
        }

        html += `<td>${string}</td>`;

      } else if (h !== "metadata") {
        // Truncate if too long
        if (string.length > 23) {
          string = string.substring(0, 20) + "...";
        }
        html += `<td alt="${escapeHtml(r[h])}">${string}</td>`;
      }
    });
    html += `</tr>`;
  });

  html += `</tbody></table>`;
  container.innerHTML = html;
}

// Issue "metadata" query for each row in parallel
async function runMetadataQueries(mainRows) {
  const mdDiv = document.getElementById('metadataResults');
  mdDiv.innerHTML = '<p><em>Loading metadata...</em></p>';
  
  try {
    // We'll do a Promise.all for each row
    const promises = mainRows.map((row, index) => 
      fetch('ajax_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          queryType: 'metadata',
          rowData: row
        })
      }).then(resp => {
        if(!resp.ok) throw new Error('HTTP error ' + resp.status);
        return resp.json().then(j => ({ index, json: j }));
      })
    );

    const resultsArray = await Promise.all(promises);
    // resultsArray = [ { index: 0, json: {...} }, { index: 1, json: {...} }, ... ]

    let html = `<h5>Metadata Results (by row)</h5>`;
    resultsArray.forEach(item => {
      const rowIndex = item.index;
      const mdData = item.json; 
      const row = mainRows[rowIndex];

      if(mdData.error) {
        html += `<div class="alert alert-warning">Metadata error: ${escapeHtml(mdData.error)}</div>`;
        return;
      }

      const metaRows = mdData.results || [];

      html += `<div class="card mb-2"><div class="card-body">`;
      html += `<strong>Row #${rowIndex+1}</strong> => `;
      // Show the main row data for reference
      const rowKeys = Object.keys(row);
      rowKeys.forEach(k => {
        if (k !== "metadata") {
          if (k == "dataset") {
            const splittedText = row[k].split("-");
            let beaconName = splittedText[splittedText.length - 1].trim();
            // Check if we have a base64 logo for this ID
            const base64logo = orgLogos[beaconName];  // orgLogos is our global dictionary

            if (base64logo) {
              // Show both the default beacon logo + the org’s base64 logo
              html += `
              <img src="data:image/png;base64,${base64logo}" style="width:3em; margin-right:0.5em;" alt="${row[k]} Logo" />
              `;
            }
            html += `<br/>${escapeHtml(k)}: <em>${row[k]}</em>`;
          }
          else {
            html += `<br/>${escapeHtml(k)}: <em>${row[k]}</em>`;
          }
        }
      });
      html += `<br/><br/>`;

      if(metaRows.length === 0) {
        // Possibly display fallback from row.metadata
        if (typeof row.metadata !== 'undefined' && row.metadata && row.metadata.length > 0) {
          html += `
            <table class="table table-sm">
              <thead>
                <tr><th>infoKey</th><th>infoValue</th></tr>
              </thead>
              <tbody>
          `;
          row.metadata.forEach(mr => {
            html += `<tr>
              <td>${escapeHtml(mr.key)}</td>
              <td>${escapeHtml(mr.val)}</td>
            </tr>`;  
          });
          html += `</tbody></table>`;
        }
        else {
          html += `<em>No metadata found</em>`;
        }
      } else {
        html += `
          <table class="table table-sm">
            <thead>
              <tr><th>infoKey</th><th>infoValue</th></tr>
            </thead>
            <tbody>
        `;
        metaRows.forEach(mr => {
          html += `<tr>
            <td>${escapeHtml(mr.infoKey)}</td>
            <td>${escapeHtml(mr.infoValue)}</td>
          </tr>`;
        });
        html += `</tbody></table>`;
      }
      html += `</div></div>`;
    });

    mdDiv.innerHTML = html;

  } catch(err) {
    mdDiv.innerHTML = `<div class="alert alert-danger">${escapeHtml(err.message)}</div>`;
  }
}

// Basic HTML-escape
function escapeHtml(str) {
  if(typeof str !== 'string') return str;
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
</script>
</body>
</html>
