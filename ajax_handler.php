<?php
session_start();
header('Content-Type: application/json');

/**************************************************************
 * CONFIG
 **************************************************************/
$graphdbEndpoint = "http://localhost:7200/repositories/Geno3";

/**************************************************************
 * READ JSON REQUEST
 **************************************************************/
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
  echo json_encode(["error" => "Invalid JSON input"]);
  exit;
}

$queryType = $data['queryType'] ?? null;
if (!$queryType) {
  echo json_encode(["error" => "No queryType provided"]);
  exit;
}

/**************************************************************
 * Caching + SPARQL Exec
 **************************************************************/
function executeSparqlQuery($endpoint, $sparql) {
  // minimal caching in session
  if (!isset($_SESSION['query_cache'])) {
    $_SESSION['query_cache'] = [];
  }
  if (isset($_SESSION['query_cache'][$sparql])) {
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

  if (curl_errno($ch)) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new Exception("cURL error: $err");
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($code != 200) {
    throw new Exception("GraphDB HTTP code $code, response: $result");
  }

  $json = json_decode($result, true);
  if (!$json) {
    throw new Exception("Invalid JSON from GraphDB: $result");
  }

  $_SESSION['query_cache'][$sparql] = $json;
  return $json;
}

/**************************************************************
 * [NEW] HELPER: Query the Beacon Network aggregator
 * This function is an EXAMPLE. You must adapt the URL/params
 * to match the real aggregator's API.
 **************************************************************/
function callBeaconNetwork($chrom, $pos, $ref, $alt) {
    // We'll transform the aggregator's response into a shape
    // similar to our local results. For instance, we might
    // return an array of arrays or an array of objects with
    // keys: dataset, chrom, pos, ref, alt
    // Below is hypothetical:
    $beaconResults = [];

    // Example aggregator endpoint (PLACEHOLDER!)
    // Check official docs for the correct path/params.
    $beacons = ["%5B0,ACpop,altruist,amplab,agha%5D", "%5Bagha-germline,agha-somatic,cogr-bc-cancer,bemgi,bioreference%5D", 
    "%5Bbipmed,brca-exchange,cafe-cardiokit,cafe-variome,cafe-central%5D", 
    "%5Bcosmic-all,cell_lines,scilifelab-clingen,cogr-consensus,conglomerate%5D", 
    "%5Bcosmic,ncbi,ebi,elixir-fi,ega%5D", "%5Bbroad,gigascience,gigascience-1,gigascience-2,thousandgenomes%5D", 
    "%5Bthousandgenomes-phase3,platinum,google,hgmd,wtsi%5D", 
    "%5Bicgc,inmegen,kaviar,lovd,garvan%5D", "%5Bmolgenis-emx2,mssng-db6,mygene2,myvariant,tsri-cadd%5D", 
    "%5Btsri-cgi,tsri-civic,tsri-clinvar,tsri-cosmic,tsri-dbnsfp%5", 
    "%5Btsri-dbsnp,tsri-docm,tsri-emv,tsri-evs,tsri-exac%5D", 
    "%5Btsri-geno2mp,tsri-gnomad_exome,tsri-gnomad_genome,tsri-grasp,tsri-gwassnps%5D", 
    "%5Btsri-mutdb,tsri-wellderly,tsri-snpedia,tsri-uniprot,narcissome%5D", 
    "%5Bnbdc-humandbs,curoverse,phenomecentral,prism,aauh-proseq%5D", 
    "%5Bcogr-queens,rdconnect,aauh-retroseq,sahgp,scilifelab%5D","%5Bcogr-sinai,clinbioinfosspa,swefreq,cmh,ucsc%5D", 
    "%5Bcytognomix,variant-matcher,vicc,wgs%5D"];

    foreach ($beacons as $beacon) {
      $url = "https://beacon-network.org/api/responses?&allele=$alt&beacon=$beacon&chrom=$chrom&pos=$pos&ref=$ref";

      // We will do a GET request
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      // Set timeouts, etc., as needed
      $result = curl_exec($ch);

      if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        echo "Beacon Network cURL error: $err";
        throw new Exception("Beacon Network cURL error: $err");
      }
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($code != 200) {
        echo "Beacon Network returned HTTP code $code, response: $result";
        throw new Exception("Beacon Network returned HTTP code $code, response: $result");
      }

      // Example parse
      $json = json_decode($result, true);
      if (!$json) {
        echo "Invalid JSON from Beacon aggregator: $result";
        throw new Exception("Invalid JSON from Beacon aggregator: $result");
      }

  

      // Suppose $json has a structure like: { data: [ { chromosome, position, reference, alternate } ] }
      if (isset($json['data']) && is_array($json['data'])) {
        foreach ($json['data'] as $item) {
          $beaconResults[] = [
            "dataset" => $beacon, // or item['dataset'] if provided
            "chrom"   => $item['chromosome'] ?? '1',
            "pos"     => $item['position'] ?? '',
            "ref"     => $item['reference'] ?? '',
            "alt"     => $item['alternate'] ?? ''
          ];
        }
      }
    }
    
    

    return $beaconResults;
}

/**************************************************************
 * SPARQL Builders
 **************************************************************/
function buildSequenceQuery($pos, $ref, $alt) {
  return <<<SPARQL
PREFIX : <https://w3id.org/hereditary/ontology/schema/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT ?dataset ?chrom ?pos ?ref ?alt
WHERE {
  ?variation a :GenomicVariation ;
             :referenceBases ?ref ;
             :alternateBases ?alt ;
             :location ?seqLoc ;
             :fromDataset ?dataset .
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

SELECT ?dataset ?chrom ?pos ?ref ?alt
WHERE {
  ?variation a :GenomicVariation ;
             :referenceBases ?ref ;
             :alternateBases ?alt ;
             :fromDataset ?dataset ;
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

SELECT ?dataset ?chrom ?pos ?ref ?alt
WHERE {
  ?variation a :GenomicVariation ;
             :referenceBases ?ref ;
             :alternateBases ?alt ;
             :fromDataset ?dataset ;
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

function buildVtQuery($infoValue) {
  // Key is constant "VT" or "SVTYPE"
  return <<<SPARQL
PREFIX : <https://w3id.org/hereditary/ontology/schema/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT ?dataset ?chrom ?ref ?alt
WHERE {
  {
    SELECT ?infoKey ?infoValue ?vInfoId
    WHERE {
      ?vInfo a :Variant_Info ;
             :hasInfoKey ?infoKey ;
             :hasInfoValue ?infoValue ;
             :hasVariantId ?vInfoId .
      FILTER (
        (?infoKey = "VT"^^rdf:PlainLiteral || ?infoKey = "SVTYPE"^^rdf:PlainLiteral) &&
        ?infoValue = "$infoValue"^^rdf:PlainLiteral
      )
    }
  }

  ?variation a :GenomicVariation ;
             :referenceBases ?ref ;
             :alternateBases ?alt ;
             :variantInternalID ?variantId ;
             :fromDataset ?dataset ;
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
    SELECT ?dataset ?chrom ?pos ?ref ?alt ?variantId
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
  if ($queryType === 'main') {
    $beaconType = $data['beaconType'] ?? null;
    if (!$beaconType) {
      echo json_encode(["error" => "No beaconType provided"]);
      exit;
    }

    $sparql = "";
    $pos = ""; 
    $ref = ""; 
    $alt = "";
    $results = []; 

    switch($beaconType) {
      case "1":
        // Sequence Query
        $chrom = $data['chrom'] ?? "1";
        $pos = $data['pos'] ?? "719853";
        $ref = $data['ref'] ?? "CAG";
        $alt = $data['alt'] ?? "C";

        // 1) Run local SPARQL
        /*$sparql = buildSequenceQuery($pos, $ref, $alt);
        $localJson = executeSparqlQuery($graphdbEndpoint, $sparql);
        $localBindings = $localJson['results']['bindings'] ?? [];
        $localResults = [];

        foreach($localBindings as $b) {
          $row = [];
          if(isset($b['dataset'])) $row['dataset'] = $b['dataset']['value'];
          if(isset($b['chrom']))   $row['chrom']   = $b['chrom']['value'];
          if(isset($b['pos']))     $row['pos']     = $b['pos']['value'];
          if(isset($b['ref']))     $row['ref']     = $b['ref']['value'];
          if(isset($b['alt']))     $row['alt']     = $b['alt']['value'];
          $localResults[] = $row;
        }
        */
        // [NEW] 2) ALSO call the Beacon Network aggregator
        try {
          $beaconResults = callBeaconNetwork($chrom, $pos, $ref, $alt); // returns array
        } catch(Exception $e) {
          // If aggregator fails for any reason, you might log or handle gracefully
          // For now, we'll just set it to empty array
          $beaconResults = [];
        }

        // 3) Merge local + aggregator
        // If you want them simply appended, do array_merge:
        $results = array_merge($localResults, $beaconResults);
        break;

      case "2":
        // Range
        $chrom = $data['chrom'] ?? "1";
        $start = $data['start'] ?? "719853";
        $end   = $data['end']   ?? "719854";
        $sparql = buildRangeQuery($chrom, $start, $end);
        $jsonResp = executeSparqlQuery($graphdbEndpoint, $sparql);
        $bindings = $jsonResp['results']['bindings'] ?? [];
        foreach($bindings as $b) {
          $row = [];
          if(isset($b['dataset'])) $row['dataset'] = $b['dataset']['value'];
          if(isset($b['chrom']))   $row['chrom']   = $b['chrom']['value'];
          if(isset($b['pos']))     $row['pos']     = $b['pos']['value'];
          if(isset($b['ref']))     $row['ref']     = $b['ref']['value'];
          if(isset($b['alt']))     $row['alt']     = $b['alt']['value'];
          $results[] = $row;
        }
        break;

      case "3":
        // Bracket
        $chrom    = $data['chrom']    ?? "1";
        $startMin = $data['startMin'] ?? "719853";
        $startMax = $data['startMax'] ?? "719890";
        $endMin   = $data['endMin']   ?? "719854";
        $endMax   = $data['endMax']   ?? "719854";
        $sparql = buildBracketQuery($chrom, $startMin, $startMax, $endMin, $endMax);
        $jsonResp = executeSparqlQuery($graphdbEndpoint, $sparql);
        $bindings = $jsonResp['results']['bindings'] ?? [];
        foreach($bindings as $b) {
          $row = [];
          if(isset($b['dataset'])) $row['dataset'] = $b['dataset']['value'];
          if(isset($b['chrom']))   $row['chrom']   = $b['chrom']['value'];
          if(isset($b['pos']))     $row['pos']     = $b['pos']['value'];
          if(isset($b['ref']))     $row['ref']     = $b['ref']['value'];
          if(isset($b['alt']))     $row['alt']     = $b['alt']['value'];
          $results[] = $row;
        }
        break;

      case "4":
        // VT
        $infoValue = $data['infoValue'] ?? "INDEL";
        $sparql = buildVtQuery($infoValue);
        $jsonResp = executeSparqlQuery($graphdbEndpoint, $sparql);
        $bindings = $jsonResp['results']['bindings'] ?? [];
        foreach($bindings as $b) {
          $row = [];
          if(isset($b['dataset'])) $row['dataset'] = $b['dataset']['value'];
          if(isset($b['chrom']))   $row['chrom']   = $b['chrom']['value'];
          if(isset($b['ref']))     $row['ref']     = $b['ref']['value'];
          if(isset($b['alt']))     $row['alt']     = $b['alt']['value'];
          $results[] = $row;
        }
        break;
      default:
        echo json_encode(["error" => "Unknown beaconType $beaconType"]);
        exit;
    }

    // Return combined results
    echo json_encode(["results" => $results]);
    exit;

  } else if ($queryType === 'metadata') {
    // [No change here]
    $row = $data['rowData'] ?? [];
    $pos = $row['pos'] ?? "";
    $ref = $row['ref'] ?? "";
    $alt = $row['alt'] ?? "";

    $sparql = buildMetadataQuery($pos, $ref, $alt);
    $jsonResp = executeSparqlQuery($graphdbEndpoint, $sparql);
    $bindings = $jsonResp['results']['bindings'] ?? [];
    $results = [];
    foreach($bindings as $b) {
      $results[] = [
        "dataset"   => $b['dataset']['value']   ?? '',
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
