#!/bin/bash
echo "=== FILE EXISTENCE ==="
for f in \
  "app/Http/Middleware/SetLocale.php" \
  "app/Repositories/Eloquent/ActivityRepository.php" \
  "app/Repositories/Contracts/ActivityRepositoryInterface.php" \
  "app/Http/Controllers/Web/Public/HomeController.php" \
  "app/Http/Controllers/Web/Public/CategoryController.php" \
  "app/Http/Controllers/Web/Public/ActivityController.php" \
  "app/Http/Requests/Web/Public/ActivitySearchRequest.php" \
  "app/Providers/AppServiceProvider.php" \
  "routes/web.php" \
  "resources/views/public/layout.blade.php" \
  "resources/views/public/home.blade.php" \
  "resources/views/public/partials/activity-card.blade.php" \
  "resources/views/public/categories/show.blade.php" \
  "resources/views/public/activities/index.blade.php" \
  "resources/views/public/activities/show.blade.php" \
  "lang/en/public.php" \
  "lang/ar/public.php" \
  "tests/Feature/Public/PublicWebsiteTest.php" \
; do
  if [ -f "$f" ]; then echo "OK       $f"; else echo "MISSING  $f"; fi
done

echo ""
echo "=== CONTENT CHECKS ==="
grep -q "public_locale" app/Http/Middleware/SetLocale.php 2>/dev/null && echo "OK       SetLocale supports public_locale" || echo "MISSING  SetLocale missing public_locale support"
grep -q "function featured" app/Repositories/Eloquent/ActivityRepository.php 2>/dev/null && echo "OK       ActivityRepository has featured()" || echo "MISSING  ActivityRepository missing featured()"
grep -q "registerViewComposers" app/Providers/AppServiceProvider.php 2>/dev/null && echo "OK       AppServiceProvider has view composer" || echo "MISSING  AppServiceProvider missing view composer"
grep -q "PublicHomeController" routes/web.php 2>/dev/null && echo "OK       web.php has public routes" || echo "MISSING  web.php missing public routes"
