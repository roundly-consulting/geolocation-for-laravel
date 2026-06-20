# Test fixtures

## `test-data/*.mmdb`

These are the public MaxMind DB test databases from the
[`maxmind/MaxMind-DB`](https://github.com/maxmind/MaxMind-DB) repository, under its
`test-data/` directory. They are distributed by MaxMind under the permissive
[MIT/Apache-2.0 test-data license](https://github.com/maxmind/MaxMind-DB/blob/main/LICENSE)
and are bundled here purely to exercise the package's **native** `.mmdb` reader.

They are **test fixtures only** — copied as static files. The package adds **no** runtime
composer dependency on `geoip2/geoip2` or `maxmind-db/reader`; the binary format is parsed
directly by `src/MaxMind/`.

| File | Purpose |
|---|---|
| `GeoIP2-City-Test.mmdb` | A realistic 28-bit City database (maps, arrays, doubles, strings). |
| `MaxMind-DB-test-decoder.mmdb` | Exercises every decoder data type (ints, floats, bytes, uint64/128, bool, …). |
| `MaxMind-DB-test-ipv4-24.mmdb` | 24-bit record-size database (IPv4). |
| `MaxMind-DB-test-ipv6-32.mmdb` | 32-bit record-size database (IPv6). |
