# Theme pagination fixtures

`tomos-quiet` and `tomos-index` contain the released template files used by the
real-theme pagination regression test. The template files are byte-for-byte
copies of the distributed packages:

- Tomos Quiet 1.0.3: `4234a54e9bb2cd792fc96f9f695be9810374cdb37a08c6fc9ce0fe2d97549f51`
- Tomos Index 1.0.4: `382494727678929c9cb13381cbe577c5308ce663913bfb06375282203d602038`

The test renders these templates through `Tomos\\App`; it does not test
`NavigationBuilder` in isolation. The built-in Tomos Blog theme is tested from
`themes/tomos-blog` directly.
