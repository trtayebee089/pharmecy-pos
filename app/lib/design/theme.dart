import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'tokens.dart';
import 'typography.dart';

/// The V2 ThemeData.
///
/// Component themes are configured centrally so a screen can drop in a plain
/// `ElevatedButton`, `TextField` or `Chip` and inherit the V2 language without
/// restating geometry. Note what is *absent*: no card borders, no dividers by
/// default, no surface tint. Depth is shadow-only.
class DsTheme {
  const DsTheme._();

  static ColorScheme get scheme => const ColorScheme.light(
    primary: DsColor.primary,
    onPrimary: DsColor.onDark,
    primaryContainer: DsColor.primarySoft,
    onPrimaryContainer: DsColor.primaryDeep,
    secondary: DsColor.brand,
    onSecondary: Colors.white,
    secondaryContainer: DsColor.brandSoft,
    onSecondaryContainer: Color(0xFF7C0E26),
    tertiary: DsColor.flame,
    onTertiary: Colors.white,
    tertiaryContainer: DsColor.flameSoft,
    onTertiaryContainer: Color(0xFF8C2412),
    error: DsColor.danger,
    onError: Colors.white,
    errorContainer: DsColor.dangerSoft,
    onErrorContainer: Color(0xFF7C0E26),
    surface: DsColor.surface,
    onSurface: DsColor.ink,
    surfaceContainerLowest: Colors.white,
    surfaceContainerLow: DsColor.canvas,
    surfaceContainer: DsColor.sunken,
    surfaceContainerHigh: DsColor.sunken,
    surfaceContainerHighest: DsColor.sunken,
    onSurfaceVariant: DsColor.inkMuted,
    outline: DsColor.hairline,
    outlineVariant: DsColor.hairline,
    shadow: Color(0xFF06231C),
  );

  static ThemeData get light {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: DsColor.canvas,
    );

    final text = DsType.textTheme();

    return base.copyWith(
      textTheme: text,
      primaryTextTheme: text,
      splashFactory: InkSparkle.splashFactory,

      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
        },
      ),

      // App bars sit flat on the canvas; V2 screens carry their own headers.
      appBarTheme: AppBarTheme(
        backgroundColor: DsColor.canvas,
        foregroundColor: DsColor.ink,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: text.headlineSmall,
        iconTheme: const IconThemeData(
          color: DsColor.ink,
          size: DsSize.iconLg,
        ),
        systemOverlayStyle: SystemUiOverlayStyle.dark,
      ),

      cardTheme: const CardThemeData(
        color: DsColor.surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: DsRadius.rMd),
      ),

      dividerTheme: const DividerThemeData(
        color: DsColor.hairline,
        thickness: 1,
        space: 1,
      ),

      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: DsColor.primary,
          foregroundColor: DsColor.onDark,
          disabledBackgroundColor: DsColor.sunken,
          disabledForegroundColor: DsColor.inkFaint,
          elevation: 0,
          minimumSize: const Size(64, DsSize.control),
          padding: const EdgeInsets.symmetric(horizontal: DsSpace.x6),
          shape: const RoundedRectangleBorder(borderRadius: DsRadius.pill),
          textStyle: text.labelLarge,
        ),
      ),

      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: DsColor.ink,
          foregroundColor: DsColor.onDark,
          minimumSize: const Size(64, DsSize.control),
          shape: const RoundedRectangleBorder(borderRadius: DsRadius.pill),
          textStyle: text.labelLarge,
        ),
      ),

      // Secondary actions are tinted fills, not outlines — outlines would
      // fight the borderless surfaces.
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: DsColor.primary,
          backgroundColor: DsColor.primarySoft,
          minimumSize: const Size(64, DsSize.control),
          padding: const EdgeInsets.symmetric(horizontal: DsSpace.x6),
          side: BorderSide.none,
          shape: const RoundedRectangleBorder(borderRadius: DsRadius.pill),
          textStyle: text.labelLarge,
        ),
      ),

      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: DsColor.primary,
          minimumSize: const Size(48, 40),
          padding: const EdgeInsets.symmetric(horizontal: DsSpace.x3),
          shape: const RoundedRectangleBorder(borderRadius: DsRadius.rSm),
          textStyle: text.labelMedium?.copyWith(
            fontWeight: FontWeight.w800,
            color: DsColor.primary,
          ),
        ),
      ),

      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(
          foregroundColor: DsColor.ink,
          minimumSize: const Size(DsSize.tap, DsSize.tap),
        ),
      ),

      // Inputs are recessed wells rather than outlined boxes.
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: DsColor.sunken,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: DsSpace.x5,
          vertical: DsSpace.x5,
        ),
        hintStyle: text.bodyMedium?.copyWith(color: DsColor.inkFaint),
        labelStyle: text.bodyMedium,
        floatingLabelStyle: text.labelMedium?.copyWith(
          color: DsColor.primary,
          fontWeight: FontWeight.w800,
        ),
        prefixIconColor: DsColor.inkSoft,
        suffixIconColor: DsColor.inkSoft,
        border: const OutlineInputBorder(
          borderRadius: DsRadius.rSm,
          borderSide: BorderSide.none,
        ),
        enabledBorder: const OutlineInputBorder(
          borderRadius: DsRadius.rSm,
          borderSide: BorderSide.none,
        ),
        focusedBorder: const OutlineInputBorder(
          borderRadius: DsRadius.rSm,
          borderSide: BorderSide(color: DsColor.primary, width: 2),
        ),
        errorBorder: const OutlineInputBorder(
          borderRadius: DsRadius.rSm,
          borderSide: BorderSide(color: DsColor.danger, width: 1.5),
        ),
        focusedErrorBorder: const OutlineInputBorder(
          borderRadius: DsRadius.rSm,
          borderSide: BorderSide(color: DsColor.danger, width: 2),
        ),
        errorStyle: text.bodySmall?.copyWith(color: DsColor.danger),
      ),

      chipTheme: ChipThemeData(
        backgroundColor: DsColor.surface,
        selectedColor: DsColor.ink,
        surfaceTintColor: Colors.transparent,
        side: BorderSide.none,
        shape: const RoundedRectangleBorder(borderRadius: DsRadius.pill),
        labelStyle: text.labelMedium,
        secondaryLabelStyle: text.labelMedium?.copyWith(color: DsColor.onDark),
        padding: const EdgeInsets.symmetric(
          horizontal: DsSpace.x4,
          vertical: DsSpace.x2 + 2,
        ),
        showCheckmark: false,
      ),

      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: DsColor.surface,
        surfaceTintColor: Colors.transparent,
        modalBackgroundColor: DsColor.surface,
        elevation: 0,
        modalElevation: 0,
        showDragHandle: true,
        dragHandleColor: DsColor.inkFaint,
        dragHandleSize: Size(40, 4),
        shape: RoundedRectangleBorder(borderRadius: DsRadius.sheet),
      ),

      dialogTheme: DialogThemeData(
        backgroundColor: DsColor.surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        shape: const RoundedRectangleBorder(borderRadius: DsRadius.rXl),
        insetPadding: const EdgeInsets.all(DsSpace.x6),
        titleTextStyle: text.headlineSmall,
        contentTextStyle: text.bodyMedium,
      ),

      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: DsColor.obsidian,
        contentTextStyle: text.bodyLarge?.copyWith(
          color: DsColor.onDark,
          fontWeight: FontWeight.w700,
        ),
        shape: const RoundedRectangleBorder(borderRadius: DsRadius.rSm),
        insetPadding: const EdgeInsets.all(DsSpace.x4),
        elevation: 0,
      ),

      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: DsColor.primary,
        linearTrackColor: DsColor.sunken,
        circularTrackColor: Colors.transparent,
      ),

      listTileTheme: ListTileThemeData(
        iconColor: DsColor.inkMuted,
        textColor: DsColor.ink,
        titleTextStyle: text.titleSmall,
        subtitleTextStyle: text.bodySmall,
        minVerticalPadding: DsSpace.x3,
        shape: const RoundedRectangleBorder(borderRadius: DsRadius.rSm),
      ),

      tooltipTheme: TooltipThemeData(
        decoration: const BoxDecoration(
          color: DsColor.obsidian,
          borderRadius: DsRadius.rXs,
        ),
        textStyle: text.bodySmall?.copyWith(color: DsColor.onDark),
      ),

      sliderTheme: const SliderThemeData(
        activeTrackColor: DsColor.primary,
        inactiveTrackColor: DsColor.sunken,
        thumbColor: DsColor.primary,
      ),

      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.selected)
              ? Colors.white
              : DsColor.inkFaint,
        ),
        trackColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.selected)
              ? DsColor.primary
              : DsColor.sunken,
        ),
        trackOutlineColor: const WidgetStatePropertyAll(Colors.transparent),
      ),
    );
  }
}
