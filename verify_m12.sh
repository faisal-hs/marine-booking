#!/bin/bash
echo "=== FILE EXISTENCE ==="
for f in \
  "app/Services/ReviewService.php" \
  "app/Http/Requests/Customer/Review/StoreReviewRequest.php" \
  "app/Http/Requests/Customer/Review/UpdateReviewRequest.php" \
  "app/Http/Resources/Review/ReviewResource.php" \
  "app/Http/Resources/Review/PublicReviewResource.php" \
  "app/Http/Resources/Review/AdminReviewResource.php" \
  "app/Http/Controllers/Api/Customer/ReviewController.php" \
  "app/Policies/ReviewPolicy.php" \
  "app/Http/Requests/Admin/Review/AdminReviewIndexRequest.php" \
  "app/Http/Controllers/Api/Admin/ReviewController.php" \
  "app/Http/Controllers/Web/Admin/ReviewController.php" \
  "resources/views/admin/reviews/index.blade.php" \
  "resources/views/admin/reviews/show.blade.php" \
  "tests/Feature/Customer/ReviewManagementTest.php" \
  "tests/Feature/Admin/ReviewModerationTest.php" \
  "tests/Feature/Public/PublicReviewsTest.php" \
; do
  if [ -f "$f" ]; then echo "OK       $f"; else echo "MISSING  $f"; fi
done

echo ""
echo "=== MODIFIED FILE CONTENT CHECKS ==="
grep -q "reviews.moderate" database/seeders/RolesAndPermissionsSeeder.php && echo "OK       RolesAndPermissionsSeeder has reviews.moderate" || echo "MISSING  RolesAndPermissionsSeeder missing reviews.moderate"
grep -q "ReviewPolicy" app/Providers/RepositoryServiceProvider.php && echo "OK       RepositoryServiceProvider has ReviewPolicy" || echo "MISSING  RepositoryServiceProvider missing ReviewPolicy"
grep -q "average_rating" app/Http/Resources/Activity/PublicActivityResource.php && echo "OK       PublicActivityResource has average_rating" || echo "MISSING  PublicActivityResource missing average_rating"
grep -q "average_rating" app/Http/Resources/Activity/ActivityListResource.php && echo "OK       ActivityListResource has average_rating" || echo "MISSING  ActivityListResource missing average_rating"
grep -q "function reviews" app/Http/Controllers/Api/Public/ActivityController.php && echo "OK       Public ActivityController has reviews()" || echo "MISSING  Public ActivityController missing reviews()"
grep -q "reviews.hide" routes/api_v1.php && echo "OK       api_v1.php has reviews routes" || echo "MISSING  api_v1.php missing reviews routes"
grep -q "Web\\\\Admin\\\\ReviewController" routes/web.php && echo "OK       web.php has ReviewController import" || echo "MISSING  web.php missing ReviewController import"
grep -q "hide_success" lang/en/admin.php && echo "OK       lang/en/admin.php has hide_success" || echo "MISSING  lang/en/admin.php missing hide_success"
grep -q "hide_success" lang/ar/admin.php && echo "OK       lang/ar/admin.php has hide_success" || echo "MISSING  lang/ar/admin.php missing hide_success"

echo ""
echo "=== COMPOSER AUTOLOAD CHECK (catches PSR-4 path mistakes too) ==="
composer dump-autoload 2>&1 | grep -i "does not comply\|Skipping" || echo "No PSR-4 violations detected"
