-- public.samples definition

-- Drop table

-- DROP TABLE public.samples;

CREATE TABLE public.samples (
	sample_id serial4 NOT NULL,
	sample_name text NOT NULL,
	CONSTRAINT samples_pkey PRIMARY KEY (sample_id),
	CONSTRAINT samples_sample_name_key UNIQUE (sample_name)
);

-- Permissions

ALTER TABLE public.samples OWNER TO ims;
GRANT ALL ON TABLE public.samples TO ims;


-- public.variants definition

-- Drop table

-- DROP TABLE public.variants;

CREATE TABLE public.variants (
	variant_id serial4 NOT NULL,
	chrom text NOT NULL,
	pos int4 NOT NULL,
	id text NOT NULL,
	"ref" text NOT NULL,
	alt text NOT NULL,
	qual numeric NULL,
	"filter" text NULL,
	CONSTRAINT variants_chrom_pos_ref_alt_key UNIQUE (chrom, pos, ref, alt),
	CONSTRAINT variants_pkey PRIMARY KEY (variant_id)
);

-- Permissions

ALTER TABLE public.variants OWNER TO ims;
GRANT ALL ON TABLE public.variants TO ims;


-- public.variant_info definition

-- Drop table

-- DROP TABLE public.variant_info;

CREATE TABLE public.variant_info (
	variant_info_id serial4 NOT NULL,
	variant_id int4 NOT NULL,
	info_key text NOT NULL,
	info_value text NULL,
	CONSTRAINT variant_info_pkey PRIMARY KEY (variant_info_id),
	CONSTRAINT variant_info_variant_id_fkey FOREIGN KEY (variant_id) REFERENCES public.variants(variant_id)
);

-- Permissions

ALTER TABLE public.variant_info OWNER TO ims;
GRANT ALL ON TABLE public.variant_info TO ims;


-- public.zygosity definition

-- Drop table

-- DROP TABLE public.zygosity;

CREATE TABLE public.zygosity (
	variant_id int4 NOT NULL,
	sample_id int4 NOT NULL,
	gt text NULL,
	CONSTRAINT zygosity_pkey PRIMARY KEY (variant_id, sample_id),
	CONSTRAINT zygosity_sample_id_fkey FOREIGN KEY (sample_id) REFERENCES public.samples(sample_id),
	CONSTRAINT zygosity_variant_id_fkey FOREIGN KEY (variant_id) REFERENCES public.variants(variant_id)
);

-- Permissions

ALTER TABLE public.zygosity OWNER TO ims;
GRANT ALL ON TABLE public.zygosity TO ims;


-- public.zygosity_default source

CREATE OR REPLACE VIEW public.zygosity_default
AS SELECT v.variant_id,
    s.sample_id,
    COALESCE(z.gt, '0|0'::text) AS gt
   FROM variants v
     CROSS JOIN samples s
     LEFT JOIN zygosity z ON z.variant_id = v.variant_id AND z.sample_id = s.sample_id;

-- Permissions

ALTER TABLE public.zygosity_default OWNER TO ims;
GRANT ALL ON TABLE public.zygosity_default TO ims;