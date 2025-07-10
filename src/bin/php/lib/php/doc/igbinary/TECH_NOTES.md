Overview
--------

The implementation of `igbinary` is largely based on php's own serializer.
Refer to https://github.com/php/php-src/blob/master/ext/standard/var_unserializer.re
and https://github.com/php/php-src/blob/master/ext/standard/var.c

Format
------

Refer to the implementation for details of how this works - this section is an overview and is not comprehensive.
Values are serialized with a byte representing the type of the value,
followed by 0 or more bytes with the serialization of the value.

Values can also be references to reuse earlier values
- `igbinary_type_string_id8` refers to a string value that was already serialized, where the identifier of that string takes up 8 bits(1 byte)
- `igbinary_type_objref8` refers to the *array of properties in* an object value that was already serialized
- `igbinary_type_object_id8` refers to a object that was already serialized.
- `igbinary_type_ref8` refers to something of any type that's part of a PHP reference group.
  `igbinary_type_ref` creates a reference group the first time it's used, and `igbinary_type_ref8` adds a value that's a reference to that reference group.

The header returned by `igbinary_serialize()` in the latest igbinary releases
always starts with `\x00\x00\x00\x02` (version 2) (long ago, this started with `\x00\x00\x00\x01`)

```c
// From src/php7/igbinary.c
enum igbinary_type {
	/* 00 */ igbinary_type_null,			/**< Null. */

	/* 01 */ igbinary_type_ref8,			/**< Array reference. */
	/* 02 */ igbinary_type_ref16,			/**< Array reference. */
	/* 03 */ igbinary_type_ref32,			/**< Array reference. */

	/* 04 */ igbinary_type_bool_false,		/**< Boolean true. */
	/* 05 */ igbinary_type_bool_true,		/**< Boolean false. */

	/* 06 */ igbinary_type_long8p,			/**< Long 8bit positive. */
	/* 07 */ igbinary_type_long8n,			/**< Long 8bit negative. */
	/* 08 */ igbinary_type_long16p,			/**< Long 16bit positive. */
	/* 09 */ igbinary_type_long16n,			/**< Long 16bit negative. */
	/* 0a */ igbinary_type_long32p,			/**< Long 32bit positive. */
	/* 0b */ igbinary_type_long32n,			/**< Long 32bit negative. */

	/* 0c */ igbinary_type_double,			/**< Double. */

	/* 0d */ igbinary_type_string_empty,	/**< Empty string. */

	/* 0e */ igbinary_type_string_id8,		/**< String id. */
	/* 0f */ igbinary_type_string_id16,		/**< String id. */
	/* 10 */ igbinary_type_string_id32,		/**< String id. */

	/* 11 */ igbinary_type_string8,			/**< String. */
	/* 12 */ igbinary_type_string16,		/**< String. */
	/* 13 */ igbinary_type_string32,		/**< String. */

	/* 14 */ igbinary_type_array8,			/**< Array. */
	/* 15 */ igbinary_type_array16,			/**< Array. */
	/* 16 */ igbinary_type_array32,			/**< Array. */

	/* 17 */ igbinary_type_object8,			/**< Object. */
	/* 18 */ igbinary_type_object16,		/**< Object. */
	/* 19 */ igbinary_type_object32,		/**< Object. */

	/* 1a */ igbinary_type_object_id8,		/**< Object string id. */
	/* 1b */ igbinary_type_object_id16,		/**< Object string id. */
	/* 1c */ igbinary_type_object_id32,		/**< Object string id. */

	/* 1d */ igbinary_type_object_ser8,		/**< Object serialized data. (when Serializable::serialize() is used) */
	/* 1e */ igbinary_type_object_ser16,	/**< Object serialized data. */
	/* 1f */ igbinary_type_object_ser32,	/**< Object serialized data. */

	/* 20 */ igbinary_type_long64p,			/**< Long 64bit positive. */
	/* 21 */ igbinary_type_long64n,			/**< Long 64bit negative. */

	/* 22 */ igbinary_type_objref8,			/**< Object reference. */
	/* 23 */ igbinary_type_objref16,		/**< Object reference. */
	/* 24 */ igbinary_type_objref32,		/**< Object reference. */

	/* 25 */ igbinary_type_ref,				/**< Simple reference */
};
```

For example, `bin2hex(igbinary_serialize(['first', true]))` is `000000021402060011056669727374060105`

```
00000002              -- 4 byte header indicating this is igbinary serialized data, version 2
14 02                 -- An array(igbinary_type_array8) of size 2 (8-bit length)
  06 00               -- The first array key - an igbinary_type_long8p (positive unsigned 8-bit integer) with value `0` (index 0)
    11 05 6669727374  --   The array value - igbinary_type_string8 with an 8-bit length of 5 and the string value `first`
  06 01               -- The second array key - the value `0`
    05                -- igbinary_type_bool_true representing the value `true`
```

A limitation of the current serialization format and unserializers is that there can only be one reference group to the same value. (`igbinary_type_ref`)

For example, `$b = $a; igbinary_serialize([&$a, &$a, &$b, &$b])` would be serialized the same way as `[&$a, &$a, &$a, &$a]`.

Serializing
-----------

See README.md for details about the format, performance, and ini options.

### Edge cases

The following types of PHP methods are invoked when serializing values.
`igbinary_serialize()` must ensure that the pointers it keeps to the values being serialized
(and temporary values returned by invoked methods) aren't modified during serialization.

1. `Serializable::serialize()` may have side effects if the serialized
   object has
2. `__sleep` is called immediately, which may have side effects
3. `__serialize` may have immediate side effects.
4. The serializers of internal classes.

Edge cases are dealt with by adding a reference to all referenceable values
so that they can't be freed prematurely.

Unserializing
-------------

### Edge cases

`igbinary_unserialize()` must ensure that the pointers it keeps to the php values it creates don't become invalid
during unserialization.

The following types of magic methods can be called during unserialization:

1. `Serializable::unserialize(string $serialized)` may have global side effects,
   but is assumed to be unable to read or modify other values that are being unserialized
   (excluding values created during other calls to `Serializable::unserialize()`, but igbinary is only concerned with the resulting object instance)
   because it must be called while unserializing everything else.
2. `__unserialize()` is expected to be safe, because everything else has already been unserialized before it gets called.
3. `__wakeup()` is expected to be safe, because everything else has already been unserialized before it gets called.
4. `__destruct()` is called, but only if&after everything was successfully unserialized
   (e.g. if `__wakeup()` throws, `__destruct()` will not be called for any directly created objects)

Sessions
--------

The standard implementation of `igbinary_serialize` and `igbinary_unserialize` are used to serialize/unserialize session data.

Refer to https://github.com/php/php-src/blob/master/ext/session/session.c for reference implementations of `PS_SERIALIZER_ENCODE_FUNC` and `PS_SERIALIZER_DECODE_FUNC` and how they change in PHP minor versions.

APCu
----

`apc_register_serializer` is called if igbinary was called with APCu support to make APCu aware that the igbinary serializer is available to serialize data to store in memory.

See the parts of the code referring to `HAVE_APCU_SUPPORT`

Redis, Memcached, etc.
----------------------

These data stores often use binary flags (or any other unambiguous indicator) to indicate that the igbinary serializer was used when saving data to the database,
and read those flags to determine which unserializer (e.g. `json_decode()`, `unserialize()`, `igbinary_unserialize()`, msgpack) to use.
