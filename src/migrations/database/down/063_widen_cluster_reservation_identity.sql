-- Reverse 063_widen_cluster_reservation_identity.sql. Reservations live for a
-- token's life: a longer identity cut here only shortens one that is about to expire.
ALTER TABLE `cluster_reservations`
      MODIFY `identity` varchar(96) COLLATE utf8_unicode_ci NOT NULL;
