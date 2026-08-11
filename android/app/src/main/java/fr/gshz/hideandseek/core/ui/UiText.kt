package fr.gshz.hideandseek.core.ui

import androidx.annotation.StringRes
import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource

/**
 * Context-free text for ViewModels: they must not hold [android.content.Context], but i18n needs
 * string resources, so [UiText] defers resolution to the composable layer where [stringResource] is.
 */
sealed class UiText {
    /** A string resource resolved at the call site. */
    data class StringResource(
        @StringRes val resId: Int,
        val args: List<Any> = emptyList(),
    ) : UiText()

    /** A plain dynamic string (e.g. server-provided detail text). */
    data class Dynamic(val value: String) : UiText()

    companion object {
        fun fromResource(@StringRes resId: Int, vararg args: Any) =
            StringResource(resId, args.toList())

        fun fromDynamic(value: String) = Dynamic(value)
    }
}

@Composable
@Suppress("detekt:SpreadOperator")
fun UiText.resolve(): String = when (this) {
    is UiText.StringResource -> {
        stringResource(resId, *args.toTypedArray())
    }
    is UiText.Dynamic -> value
}
