-- SAMPLES VIEW
SELECT * FROM EXA.public.samples
UNION ALL
SELECT * FROM GRACE.public.samples;


-- VARIANTS INFO VIEW
SELECT * FROM EXA.public.variant_info
UNION ALL
SELECT * FROM GRACE.public.variant_info;


-- VARIANTS VIEW
SELECT *, 'cineca' AS dataset FROM EXA.public.variants 
UNION ALL
SELECT *, '1000geno' AS dataset FROM GRACE.public.variants 


-- ZYGOSITIES VIEW
SELECT * FROM EXA.public.zygosity_default
UNION ALL
SELECT * FROM GRACE.public.zygosity_default;

