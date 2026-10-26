# Photo provenance: homepage photographs

The homepage uses **two authentic photographs of Dr. Abdul Qader Sankari** (الدكتور عبد القادر سنكري). Both were published by **HAND Organization (Human Aid and Development)**, which he founded and chairs.

- No photographs were supplied in `docs/`, so these were sourced from verified primary sources.
- No image was generated, retouched, reconstructed or replaced with stock.
- Accessed: 2026-10-26.

| | Portrait (opening) | Umayyad Mosque visit (legacy section) |
| --- | --- | --- |
| **Source page** | HAND, *About us*, section "HAND's founder and Chairman": <https://www.hand.ngo/about-us> | HAND news, *In the historic surroundings of the Umayyad Mosque in Damascus* (11 May 2026, by Samer Daboul): <https://www.hand.ngo/blog/news-reports-1/in-the-historic-surroundings-of-the-umayyad-mosque-in-damascus-76> |
| **Image URL** | `https://hand.ngo/web/image/5854-0599cf30/Blog%20Post%20%27%D8%AD%D9%84%D8%A8%20%D8%B3%D8%AA%20%D8%A7%D9%84%D9%83%D9%84%27%20cover%20image.webp` (the original behind the About page's resized copy `6685`) | `https://hand.ngo/web/image/6650-c1134336/Blog%20Post%20%27In%20the%20historic%20surroundings%20of%20the%20Umayyad%20Mosque%20in%20Damascus%27%20cover%20image.webp` (the article's cover image) |
| **Downloaded file** | WebP, 1200 × 790, SHA-256 `6689c38c8a6e9febb38a70768e144c4370f94c1d5efed2c71cb0d0168e48721f` | WebP, 1920 × 1220, SHA-256 `e40c8f8de9b68cb14da27294fd08d88685b6d2a6f4c8469cd2aaf77e6a4b2aeb` |
| **Subject** | Head-and-shoulders portrait of Dr. Sankari: white ghutra, black agal, dark-framed glasses, white beard, plain white background. | Eleven men standing in a hall of the Umayyad Mosque, beneath a Qur'anic mosaic frieze. Dr. Sankari wears Emirati dress (white ghutra, dark thobe), glasses and a white beard. |
| **Verified context** | A studio-style portrait, not an event photo. The source does not give its date or place. | His visit to the Umayyad Mosque during his first visit to Damascus since 2011, accompanied by officials and representatives of HAND and Al-Rowad. This was a **formal visit**, and the page describes it as such, not as fieldwork. |
| **Attribution shown on the page** | «الصورة: منظمة HAND», linked to the source page | «الصورة: منظمة HAND», linked to the source article. The HAND watermark in the image is kept. |
| **Usage limitation stated by the source** | The site footer reads "Copyright © 2025 HAND Human Aid & Development \| All Rights Reserved". No reuse licence is stated. | Same |

## How identity was verified

**Portrait.** Three independent placements by organisations he chairs name the person in this portrait:

1. HAND's About page shows it in the section headed "HAND's founder and Chairman", directly beside "Dr. Abdulkader Sankari is the Founder and Chairman of the Board of Trustees of HAND".
2. The file is the cover image (copy `5890`) of HAND's post *USD 40 Million Donation by Dr. Sankari to Support Syria's Recovery again* (20 Dec 2025). That post's inline graphic (`5883`) shows the **same portrait** with the Arabic caption «الدكتور عبد القادر سنكري — رئيس مجلس الأمناء».
3. Al-Rowad Foundation's About page (<https://www.alrowadngo.sy/?page_id=2914>) shows a separate portrait of the same man, with `alt="الدكتور عبد القادر سنكري"`. That image was **not used** because its background is a composite of national flags.

**Visit photo.**

- The article states that Dr. Sankari visited the mosque, and this photo is the article's own cover.
- The article does **not** say where he stands in the group. He was identified by comparison with the captioned portrait above: same glasses, beard and dress.
- The page therefore makes no positional claim ("fourth from left"). Its caption describes the visit, and its alt text describes him by his visible dress.
- The other people in the photo are not named on the page.

**Not used**, because their context could not be verified or they do not show Dr. Sankari:

- HAND's classroom and field photos, which show children and staff;
- Al-Rowad's uncaptioned slider photos;
- formal meeting and signing photos.

## Derivatives in `frontend/src/assets/photos/`

Made with Pillow 13 (outside the project's dependency tree).

- **Resampling:** Lanczos.
- **Encoding:** WebP at quality 82, plus one progressive JPEG at quality 82 as a fallback for browsers without WebP.
- **Colour:** the sRGB ICC profile is kept.
- **No upscaling:** the largest derivative never exceeds the source crop.
- **Untouched:** no retouching, colour change or face edit.
- **Originals are not committed:** they were not supplied to the project. Their URLs and hashes above identify them exactly.

| File set | Crop from source (px) | Widths | Used |
| --- | --- | --- | --- |
| `sankari-portrait-{400,640,800}.webp`, `-800.jpg` | x 0–800, y 0–790: keeps the whole head and shoulders and drops the empty white background on the right of the source image | 400, 640, 800 | Opening, all widths. CSS `object-fit: cover` shows a 4:3 frame (`object-position: 55% 30%`) below 768px and a 4:5 frame (`62% 50%`) from 768px up. The face stays inside both. |
| `sankari-umayyad-mosque-visit-{960,1600}.webp`, `-1600.jpg` | Full frame, uncropped | 960, 1600 | Legacy section, 768px and up |
| `sankari-umayyad-mosque-visit-narrow-{480,912}.webp`, `-912.jpg` | x 0–912, y 110–1220: the mosaic frieze, Dr. Sankari and the four men nearest him, and the HAND watermark | 480, 912 | Legacy section below 768px, where the full group would make faces too small. Art-directed with `<picture media>`. |

## Open items for the award owner

- **Permission.** HAND states "All Rights Reserved" and publishes no reuse licence. HAND and Al-Rowad (the award's implementing organisation) are both founded and chaired by Dr. Sankari, but **written permission from HAND to reuse these two photographs on the award site should be confirmed before public launch.**
- **Prominence.** HAND notes that Dr. Sankari has often asked that his contributions remain unpublicised. The award owner should confirm that he, or his office, approves his portrait leading the homepage.
- **Resolution.** The best available portrait source is 1200 × 790. At 1440px on a high-density (2×) screen, it shows at about 1.4 device pixels per image pixel, so it is slightly soft. It is sharp at 1×. An original, higher-resolution file from HAND or the photographer would be better.
- **Photographer credit.** The sources do not name the photographer. If HAND supplies a name, add it to the captions.
