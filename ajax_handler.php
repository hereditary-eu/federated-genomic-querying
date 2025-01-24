<?php
session_start();
header('Content-Type: application/json');

/**************************************************************
 * CONFIG
 **************************************************************/
$graphdbEndpoint = "http://localhost:7200/repositories/Geno3";
// If you need auth, uncomment and set user/password:

/**************************************************************
 * READ JSON REQUEST
 **************************************************************/
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if(!$data) {
  echo json_encode(["error" => "Invalid JSON input"]);
  exit;
}

$queryType = $data['queryType'] ?? null;
if(!$queryType) {
  echo json_encode(["error" => "No queryType provided"]);
  exit;
}

/**************************************************************
 * Caching + SPARQL Exec
 **************************************************************/
function executeSparqlQuery($endpoint, $sparql) {
  // minimal caching in session
  if(!isset($_SESSION['query_cache'])) {
    $_SESSION['query_cache'] = [];
  }
  if(isset($_SESSION['query_cache'][$sparql])) {
    return $_SESSION['query_cache'][$sparql];
  }

  // cURL
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $endpoint);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/sparql-query',
    'Accept: application/sparql-results+json'
  ]);
  // If needed for auth:
  curl_setopt($ch, CURLOPT_USERPWD, "USERNAME:PASSWORD");

  curl_setopt($ch, CURLOPT_POSTFIELDS, $sparql);
  $result = curl_exec($ch);

  if(curl_errno($ch)) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new Exception("cURL error: $err");
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if($code != 200) {
    throw new Exception("GraphDB HTTP code $code, response: $result");
  }

  $json = json_decode($result, true);
  if(!$json) {
    throw new Exception("Invalid JSON from GraphDB: $result");
  }

  $_SESSION['query_cache'][$sparql] = $json;
  return $json;
}

/**************************************************************
 * SPARQL Builders
 **************************************************************/
function buildSequenceQuery($pos, $ref, $alt) {
  return <<<SPARQL
PREFIX : <https://w3id.org/hereditary/ontology/schema/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT ?chrom ?pos ?ref ?alt
WHERE {
  ?variation a :GenomicVariation ;
             :referenceBases ?ref ;
             :alternateBases ?alt ;
             :location ?seqLoc .
  ?seqLoc :sequenceInterval ?seqInt ;
          :locationChr ?chrom .
  ?seqInt :sequenceIntervalStart ?pos .

  OPTIONAL {
    ?seqLoc :referenceSequence ?refName .
    FILTER(?refName = "GRCh38"^^rdf:PlainLiteral)
  }

  FILTER(
    ?pos = "$pos"^^rdf:PlainLiteral &&
    ?ref = "$ref"^^rdf:PlainLiteral &&
    ?alt = "$alt"^^rdf:PlainLiteral
  )
}
SPARQL;
}

function buildRangeQuery($chrom, $start, $end) {
  return <<<SPARQL
PREFIX : <https://w3id.org/hereditary/ontology/schema/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT ?chrom ?pos ?ref ?alt
WHERE {
  ?variation a :GenomicVariation ;
             :referenceBases ?ref ;
             :alternateBases ?alt ;
             :location ?seqLoc .
  ?seqLoc :sequenceInterval ?seqInt ;
          :locationChr ?chrom .
  ?seqInt :sequenceIntervalStart ?pos .
  ?seqInt :sequenceIntervalEnd ?theEnd .

  OPTIONAL {
    ?seqLoc :referenceSequence ?refName .
    FILTER(?refName = "GRCh38"^^rdf:PlainLiteral)
  }

  FILTER(
    ?chrom = "$chrom"^^rdf:PlainLiteral &&
    (
      ?pos = "$start"^^rdf:PlainLiteral ||
      ?theEnd = "$end"^^rdf:PlainLiteral
    )
  )
}
SPARQL;
}

function buildBracketQuery($chrom, $startMin, $startMax, $endMin, $endMax) {
  return <<<SPARQL
PREFIX : <https://w3id.org/hereditary/ontology/schema/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

SELECT ?chrom ?pos ?ref ?alt
WHERE {
  ?variation a :GenomicVariation ;
             :referenceBases ?ref ;
             :alternateBases ?alt ;
             :location ?seqLoc .
  ?seqLoc :sequenceInterval ?seqInt ;
          :locationChr ?chrom .
  ?seqInt :sequenceIntervalStart ?pos .
  ?seqInt :sequenceIntervalEnd ?theEnd .

  FILTER (
    ?chrom = "$chrom"^^rdf:PlainLiteral &&
    (
      (xsd:integer(?pos) >= $startMin && xsd:integer(?pos) <= $startMax) ||
      (xsd:integer(?theEnd) >= $endMin && xsd:integer(?theEnd) <= $endMax)
    )
  )
}
SPARQL;
}

/**
 * The new "VT=INDEL" style query with infoKey=VT, infoValue param
 */
function buildVtQuery($infoValue) {
  // Key is constant "VT", value is user param (default "INDEL")
  return <<<SPARQL
PREFIX : <https://w3id.org/hereditary/ontology/schema/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT ?chrom ?ref ?alt
WHERE {
  {
    SELECT ?infoKey ?infoValue ?vInfoId
    WHERE {
      ?vInfo a :Variant_Info ;
             :hasInfoKey ?infoKey ;
             :hasInfoValue ?infoValue ;
             :hasVariantId ?vInfoId .
      FILTER (
        ?infoKey = "VT"^^rdf:PlainLiteral &&
        ?infoValue = "$infoValue"^^rdf:PlainLiteral
      )
    }
  }

  ?variation a :GenomicVariation ;
             :referenceBases ?ref ;
             :alternateBases ?alt ;
             :variantInternalID ?variantId ;
             :location ?seqLoc .
  ?seqLoc :sequenceInterval ?seqInt ;
          :locationChr ?chrom .

  FILTER(?variantId = ?vInfoId)
}
SPARQL;
}

function buildMetadataQuery($pos, $ref, $alt) {
  return <<<SPARQL
PREFIX : <https://w3id.org/hereditary/ontology/schema/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT ?infoKey ?infoValue
WHERE {
  {
    SELECT ?chrom ?pos ?ref ?alt ?variantId
    WHERE {
      ?variation a :GenomicVariation ;
                 :referenceBases ?ref ;
                 :alternateBases ?alt ;
                 :variantInternalID ?variantId ;
                 :location ?seqLoc .
      ?seqLoc :sequenceInterval ?seqInt ;
              :locationChr ?chrom .
      ?seqInt :sequenceIntervalStart ?pos .
      FILTER(
        ?pos = "$pos"^^rdf:PlainLiteral &&
        ?ref = "$ref"^^rdf:PlainLiteral &&
        ?alt = "$alt"^^rdf:PlainLiteral
      )
    }
  }
  ?vInfo a :Variant_Info ;
          :hasInfoKey ?infoKey ;
          :hasInfoValue ?infoValue ;
          :hasVariantId ?vInfoId .
  FILTER(?variantId = ?vInfoId)
}
SPARQL;
}

/**************************************************************
 * MAIN LOGIC
 **************************************************************/
try {
  if($queryType === 'main') {
    // We read 'beaconType' to see which query user wants
    $beaconType = $data['beaconType'] ?? null;
    if(!$beaconType) {
      echo json_encode(["error" => "No beaconType provided"]);
      exit;
    }

    // Build the correct SPARQL
    $sparql = "";
    switch($beaconType) {
      case "1":
        // Sequence
        $pos = $data['pos'] ?? "719853";
        $ref = $data['ref'] ?? "CAG";
        $alt = $data['alt'] ?? "C";
        $sparql = buildSequenceQuery($pos, $ref, $alt);
        break;
      case "2":
        // Range
        $chrom = $data['chrom'] ?? "1";
        $start = $data['start'] ?? "719853";
        $end   = $data['end']   ?? "719854";
        $sparql = buildRangeQuery($chrom, $start, $end);
        break;
      case "3":
        // Bracket
        $chrom    = $data['chrom']    ?? "1";
        $startMin = $data['startMin'] ?? "719853";
        $startMax = $data['startMax'] ?? "719890";
        $endMin   = $data['endMin']   ?? "719854";
        $endMax   = $data['endMax']   ?? "719854";
        $sparql = buildBracketQuery($chrom, $startMin, $startMax, $endMin, $endMax);
        break;
      case "4":
        // The new "VT" query
        $infoValue = $data['infoValue'] ?? "INDEL";
        $sparql = buildVtQuery($infoValue);
        break;
      default:
        echo json_encode(["error" => "Unknown beaconType $beaconType"]);
        exit;
    }

    // Execute + parse
    $jsonResp = executeSparqlQuery($graphdbEndpoint, $sparql);
    $bindings = $jsonResp['results']['bindings'] ?? [];
    $results = [];

    // We unify "pos" if the query didn't produce it, but the new "VT" query does not have ?pos
    // We'll extract columns that exist.
    // We know the new query has ?chrom, ?ref, ?alt. Others have ?chrom, ?pos, ?ref, ?alt, etc.
    foreach($bindings as $b) {
      $row = [];
      if(isset($b['chrom'])) $row['chrom'] = $b['chrom']['value'];
      if(isset($b['pos']))   $row['pos']   = $b['pos']['value'];
      if(isset($b['ref']))   $row['ref']   = $b['ref']['value'];
      if(isset($b['alt']))   $row['alt']   = $b['alt']['value'];
      // If your data has other columns (like ?theEnd), you could handle them similarly
      $results[] = $row;
    }

    echo json_encode(["results" => $results]);
    exit;

  } else if($queryType === 'metadata') {
    // We expect 'rowData' with at least pos, ref, alt
    $row = $data['rowData'] ?? [];
    $pos = $row['pos'] ?? "";
    $ref = $row['ref'] ?? "";
    $alt = $row['alt'] ?? "";

    // For the new query type 4, we skip metadata from the front-end. 
    // So we won't even get here for that type. 
    // But if we do, pos/ref/alt might be empty => produce no results.

    $sparql = buildMetadataQuery($pos, $ref, $alt);
    $jsonResp = executeSparqlQuery($graphdbEndpoint, $sparql);
    $bindings = $jsonResp['results']['bindings'] ?? [];
    $results = [];
    foreach($bindings as $b) {
      $results[] = [
        "infoKey"   => $b['infoKey']['value']   ?? '',
        "infoValue" => $b['infoValue']['value'] ?? ''
      ];
    }

    echo json_encode(["results" => $results]);
    exit;

  } else {
    echo json_encode(["error" => "Unknown queryType: $queryType"]);
    exit;
  }

} catch(Exception $ex) {
  echo json_encode(["error" => $ex->getMessage()]);
  exit;
}
