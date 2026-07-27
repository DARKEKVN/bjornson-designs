# Bjornson Designs Re-Envisioned Site

This is a static premium redesign concept for Bjornson Designs.

## What is included

- `index.html` - premium homepage
- `portfolio.html` - full kitchen, bathroom, and showroom gallery
- `process.html` - design/showroom process page
- `team.html` - full staff page
- `reviews.html` - homeowner reviews page
- `contact.html` - callback request page
- `styles.css` - responsive visual system and layout
- `app.js` - mobile navigation, portfolio filtering, lightbox, and contact form submission
- `send-lead.php` - PHP mail handler that sends callback requests to rene@bjornsondesigns.com
- `assets/img/source/` - downloaded source imagery from https://bjornsondesigns.ca/
- `assets/img/generated/` - generated premium hero imagery for the redesign
- `assets/data/source-assets.json` - manifest of scraped source assets and their original URLs

## Generated hero prompts

Kitchen hero:
Photorealistic upscale modern custom kitchen with warm wood cabinetry, refined stone counters, integrated appliances, subtle under-cabinet lighting, and negative space for website copy.

Showroom hero:
Photorealistic high-end cabinetry design studio with material samples, cabinet door fronts, hardware, wood finishes, and a refined consultation table.

## Notes

The callback forms submit to `send-lead.php`, which emails rene@bjornsondesigns.com through the hosting server's PHP mail transport. No SMTP password is stored in this repository.
