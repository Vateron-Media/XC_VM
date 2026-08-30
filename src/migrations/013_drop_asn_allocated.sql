-- Drop the unused `allocated` column from blocked_asns. It was never read or
-- written by the application (display columns are asn/isp/domain/country/num_ips/
-- type; the block decision uses only asn + blocked). The ASN catalog is now kept
-- current by AsnCatalogSync from the release master file, which does not carry an
-- allocation date. Existing rows and the user-owned `blocked` flag are preserved.
ALTER TABLE `blocked_asns` DROP COLUMN IF EXISTS `allocated`;
