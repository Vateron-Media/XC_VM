-- An HMAC identity is `<hmac_id>_<identifier>` (StoredConnections::identity()),
-- the identifier cut to 255 bytes: up to 267 characters. At varchar(96) the
-- panel's non-strict sql_mode truncated it, so viewers whose identifiers share
-- their first 94 characters were counted as one by connection admission.
ALTER TABLE `cluster_reservations`
      MODIFY `identity` varchar(267) COLLATE utf8_unicode_ci NOT NULL;
