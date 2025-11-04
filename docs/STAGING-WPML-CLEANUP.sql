-- ============================================
-- WPML String Cleanup for Staging Database
-- Plugin: intersoccer-product-variations
-- Issue: Emoji characters in cached strings
-- Database Prefix: wp_1244388_
-- ============================================

-- STEP 1: View problematic strings before deletion
-- (Run this first to see what will be deleted)

SELECT 
    s.id,
    s.name,
    s.value,
    s.context,
    s.domain_name_context_md5
FROM wp_1244388_icl_strings s
WHERE s.context = 'intersoccer-product-variations'
ORDER BY s.name;

-- STEP 2: Count how many string translations will be affected

SELECT COUNT(*) as translation_count
FROM wp_1244388_icl_string_translations st
INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

-- STEP 3: Delete all string translations for this plugin
-- (Delete translations first to avoid orphaned records)

DELETE st 
FROM wp_1244388_icl_string_translations st
INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

-- Verify deletion
SELECT COUNT(*) as remaining_translations
FROM wp_1244388_icl_string_translations st
INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';
-- Should return 0

-- STEP 4: Delete all string registrations for this plugin

DELETE FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations';

-- Verify deletion
SELECT COUNT(*) as remaining_strings
FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations';
-- Should return 0

-- STEP 5: Clean up any orphaned string translations
-- (Safety measure - removes any translations without parent strings)

DELETE FROM wp_1244388_icl_string_translations 
WHERE string_id NOT IN (SELECT id FROM wp_1244388_icl_strings);

-- STEP 6: Verify cleanup is complete

SELECT 
    'Remaining strings' as check_type,
    COUNT(*) as count
FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations'
UNION ALL
SELECT 
    'Remaining translations' as check_type,
    COUNT(*) as count
FROM wp_1244388_icl_string_translations st
INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';
-- Both counts should be 0

-- ============================================
-- OPTIONAL: View all WPML string contexts
-- (Useful to see what other plugins have strings)
-- ============================================

SELECT 
    context,
    COUNT(*) as string_count
FROM wp_1244388_icl_strings
GROUP BY context
ORDER BY string_count DESC;

-- ============================================
-- OPTIONAL: Search for emoji strings in other contexts
-- (Check if other plugins have the same issue)
-- ============================================

SELECT 
    s.context,
    s.name,
    s.value
FROM wp_1244388_icl_strings s
WHERE s.name REGEXP '[😀-🙏🌀-🗿🚀-🛿]'
   OR s.value REGEXP '[😀-🙏🌀-🗿🚀-🛿]'
ORDER BY s.context, s.name;

-- ============================================
-- NEXT STEPS AFTER RUNNING THIS SQL:
-- ============================================
-- 1. In WordPress admin:
--    - Deactivate "InterSoccer Product Variations" plugin
--    - Delete the plugin completely
--
-- 2. On your local machine:
--    cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations
--    ./deploy.sh
--
-- 3. In WordPress admin:
--    - Activate the plugin
--    - WPML will register new emoji-free strings
--
-- 4. Verify in WPML → String Translation:
--    - Should see "▶ Start Automated Update" (not 🚀)
--    - Should see "■ Stop Processing" (not ⏹️)
--    - Should see "↓ Download Results Log" (not 📥)
-- ============================================

