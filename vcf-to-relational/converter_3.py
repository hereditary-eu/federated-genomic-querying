import sys

if len(sys.argv) < 3:
    print("Usage: python vcf_to_sql.py <input.vcf> <output.sql>")
    sys.exit(1)

vcf_file = sys.argv[1]
output_file = sys.argv[2]

# Ask the user if they want to preprocess (remove duplicates)
choice = input("Do you want to preprocess the VCF to remove duplicates? (y/n): ").strip().lower()

preprocess = (choice == 'y')

if preprocess:
    # We'll read all lines and remove duplicates
    unique_lines = []
    seen_lines = set()
    with open(vcf_file, 'r') as f:
        for line in f:
            if line.startswith('#'):
                # Keep all header lines as is
                unique_lines.append(line)
            else:
                if line not in seen_lines:
                    seen_lines.add(line)
                    unique_lines.append(line)
    # Now unique_lines contains no duplicates
    lines_to_process = unique_lines
else:
    # Just read the file as is, no duplicate removal
    with open(vcf_file, 'r') as f:
        lines_to_process = f.readlines()
        
# Parse sample names from VCF header
samples = []
for line in lines_to_process:
    if line.startswith("#CHROM"):
        header = line.strip().split('\t')
        samples = header[9:]
        break

if not samples:
    print("No samples found in the VCF header.")
    sys.exit(1)

# Assign sample IDs starting from 1
sample_id_map = {s: i+1 for i, s in enumerate(samples)}

# Prepare data structures to hold insert statements
variant_rows = []
variant_info_rows = []
sample_rows = []
zygosity_rows = []

# Insert samples
for s in samples:
    # Escape single quotes if any
    s_esc = s.replace("'", "''")
    sample_rows.append(f"INSERT INTO samples (sample_name) VALUES ('{s_esc}');")

seen_variants = {}  # Map (chrom, pos, ref, alt) to variant_id
variant_id_counter = 0

for line in lines_to_process:
    if line.startswith("#"):
        continue
    parts = line.strip().split('\t')
    chrom = parts[0]
    pos = int(parts[1])
    int_id = parts[2]
    ref = parts[3]
    alt = parts[4]
    qual = parts[5] if parts[5] != '.' else 'NULL'
    filtr = parts[6] if parts[6] != '.' else None
    info_str = parts[7]
    fmt = parts[8]
    sample_values = parts[9:]

    key = (chrom, pos, ref, alt)
    if key not in seen_variants:
        variant_id_counter += 1
        v_id = variant_id_counter
        seen_variants[key] = v_id

        # Escape strings
        chrom_esc = chrom.replace("'", "''")
        ref_esc = ref.replace("'", "''")
        alt_esc = alt.replace("'", "''")

        if filtr:
            filtr_esc = filtr.replace("'", "''")
            filter_val = f"'{filtr_esc}'"
        else:
            filter_val = 'NULL'

        variant_rows.append(
            f"INSERT INTO variants (chrom, pos, id, ref, alt, qual, filter) "
            f"VALUES ('{chrom_esc}', {pos}, '{int_id}', '{ref_esc}', '{alt_esc}', {qual}, {filter_val});"
        )

        # Parse INFO field and insert into variant_info
        if info_str != ".":
            info_items = info_str.split(';')
            for item in info_items:
                if '=' in item:
                    k, v = item.split('=', 1)
                else:
                    k = item
                    v = 'TRUE'
                k_esc = k.replace("'", "''")
                v_esc = v.replace("'", "''")
                variant_info_rows.append(
                    f"INSERT INTO variant_info (variant_id, info_key, info_value) VALUES ({v_id}, '{k_esc}', '{v_esc}');"
                )
    else:
        v_id = seen_variants[key]

    # Parse genotype fields (GT assumed first subfield)
    for i, val in enumerate(sample_values):
        gt = val.split(':')[0]
        gt_esc = gt.replace("'", "''")
        # Skip inserting '0|0' entries to reduce size
        if gt_esc == '0|0':
            continue
        sample_id = sample_id_map[samples[i]]
        zygosity_rows.append(
            f"INSERT INTO zygosity (variant_id, sample_id, gt) VALUES ({v_id}, {sample_id}, '{gt_esc}');"
        )

# Write out all SQL to the output file
with open(output_file, 'w') as out:
    out.write("-- Schema\n")
    out.write("CREATE TABLE variants (\n"
              "    variant_id SERIAL PRIMARY KEY,\n"
              "    chrom TEXT NOT NULL,\n"
              "    pos INT NOT NULL,\n"
              "    id TEXT NOT NULL,\n"
              "    ref TEXT NOT NULL,\n"
              "    alt TEXT NOT NULL,\n"
              "    qual NUMERIC,\n"
              "    filter TEXT,\n"
              "    UNIQUE(chrom, pos, ref, alt)\n"
              ");\n\n")

    out.write("CREATE TABLE variant_info (\n"
              "    variant_info_id SERIAL PRIMARY KEY,\n"
              "    variant_id INT NOT NULL REFERENCES variants(variant_id),\n"
              "    info_key TEXT NOT NULL,\n"
              "    info_value TEXT\n"
              ");\n\n")

    out.write("CREATE TABLE samples (\n"
              "    sample_id SERIAL PRIMARY KEY,\n"
              "    sample_name TEXT NOT NULL UNIQUE\n"
              ");\n\n")

    out.write("CREATE TABLE zygosity (\n"
              "    variant_id INT NOT NULL REFERENCES variants(variant_id),\n"
              "    sample_id INT NOT NULL REFERENCES samples(sample_id),\n"
              "    gt TEXT,\n"
              "    PRIMARY KEY(variant_id, sample_id)\n"
              ");\n\n")
    
    out.write("CREATE OR REPLACE VIEW public.zygosity_default\n"
              "AS SELECT v.variant_id,\n"
              "s.sample_id,\n"
              "COALESCE(z.gt, '0|0'::text) AS gt\n"
              "FROM variants v\n"
              "CROSS JOIN samples s\n"
              "LEFT JOIN zygosity z ON z.variant_id = v.variant_id AND z.sample_id = s.sample_id;\n")

    out.write("-- Insert samples\n")
    for s_sql in sample_rows:
        out.write(s_sql + "\n")

    out.write("\n-- Insert variants\n")
    for v_sql in variant_rows:
        out.write(v_sql + "\n")

    out.write("\n-- Insert variant_info\n")
    for vi_sql in variant_info_rows:
        out.write(vi_sql + "\n")

    out.write("\n-- Insert zygosity\n")
    for z_sql in zygosity_rows:
        out.write(z_sql + "\n")

print(f"SQL dump created in {output_file}")
