package fr.gshz.hideandseek.feature.settings.avatar

import android.app.Activity
import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas as AndroidCanvas
import android.graphics.Color as AndroidColor
import android.graphics.Paint
import android.graphics.RectF
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ZoomIn
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.FilterQuality
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathFillType
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.clipRect
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import androidx.core.view.WindowCompat
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.theme.Spacing
import kotlin.math.ceil
import kotlin.math.floor
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

internal const val AVATAR_EXPORT_SIZE = 512
private const val SCRIM_ALPHA = 0.62f
private const val GUIDE_INSET_DP = 2

private sealed interface AvatarSourceState {
    data object Loading : AvatarSourceState
    data class Ready(val bitmap: Bitmap) : AvatarSourceState
    data object Failed : AvatarSourceState
}

/**
 * Square crop editor. The scrim previews the circular pin; the corners outside the
 * circle are still saved.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
@Suppress("LongParameterList", "LongMethod")
internal fun AvatarCropScreen(
    source: Uri,
    isUploading: Boolean,
    error: ErrorType?,
    errorKey: String?,
    errorArgs: Map<String, String>?,
    onCancel: () -> Unit,
    onUsePhoto: (Bitmap) -> Unit,
) {
    val sourceState = rememberAvatarSource(source)
    var zoom by rememberSaveable { mutableStateOf(MIN_AVATAR_ZOOM) }
    var offsetX by rememberSaveable { mutableStateOf(0f) }
    var offsetY by rememberSaveable { mutableStateOf(0f) }
    val transform = AvatarCropTransform(zoom, offsetX, offsetY)
    val applyTransform: (AvatarCropTransform) -> Unit = { next ->
        zoom = next.zoom
        offsetX = next.offsetX
        offsetY = next.offsetY
    }
    val ready = sourceState as? AvatarSourceState.Ready

    DarkSystemBars()
    BackHandler {
        if (!isUploading) onCancel()
    }

    Scaffold(
        containerColor = Color.Black,
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.avatar_crop_title)) },
                navigationIcon = {
                    IconButton(onClick = onCancel, enabled = !isUploading) {
                        Icon(
                            Icons.Filled.Close,
                            contentDescription = stringResource(R.string.avatar_crop_close),
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Color.Black,
                    titleContentColor = Color.White,
                    navigationIconContentColor = Color.White,
                ),
            )
        },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = Spacing.lg),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = stringResource(R.string.avatar_crop_hint),
                color = Color.White.copy(alpha = 0.72f),
                style = MaterialTheme.typography.bodySmall,
                textAlign = TextAlign.Center,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(vertical = Spacing.sm),
            )
            Box(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth(),
                contentAlignment = Alignment.Center,
            ) {
                when (val state = sourceState) {
                    AvatarSourceState.Loading -> CropLoading()
                    AvatarSourceState.Failed -> CropFailed()
                    is AvatarSourceState.Ready -> AvatarCropViewport(
                        bitmap = state.bitmap,
                        transform = transform,
                        enabled = !isUploading,
                        onTransformChange = applyTransform,
                    )
                }
            }
            error?.let {
                ErrorText(
                    error = it,
                    errorKey = errorKey,
                    errorArgs = errorArgs,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(bottom = Spacing.sm),
                )
            }
            if (ready != null) {
                ZoomRow(
                    zoom = zoom,
                    enabled = !isUploading,
                    onZoomChange = { value ->
                        applyTransform(transform.copy(zoom = value).clamped(ready.bitmap.width, ready.bitmap.height))
                    },
                )
                CropActions(
                    isUploading = isUploading,
                    onReset = { applyTransform(AvatarCropTransform()) },
                    onUsePhoto = { onUsePhoto(cropAvatar(ready.bitmap, transform)) },
                )
            }
            Spacer(Modifier.height(Spacing.sm))
        }
    }
}

@Composable
private fun DarkSystemBars() {
    val view = LocalView.current
    if (view.isInEditMode) return

    DisposableEffect(view) {
        val controller = (view.context as? Activity)?.window
            ?.let { WindowCompat.getInsetsController(it, view) }
        val lightStatusBars = controller?.isAppearanceLightStatusBars
        val lightNavigationBars = controller?.isAppearanceLightNavigationBars
        controller?.isAppearanceLightStatusBars = false
        controller?.isAppearanceLightNavigationBars = false

        onDispose {
            if (controller != null) {
                lightStatusBars?.let { controller.isAppearanceLightStatusBars = it }
                lightNavigationBars?.let { controller.isAppearanceLightNavigationBars = it }
            }
        }
    }
}

@Composable
private fun rememberAvatarSource(uri: Uri): AvatarSourceState {
    val context: Context = LocalContext.current
    val state by produceState<AvatarSourceState>(AvatarSourceState.Loading, uri) {
        value = withContext(Dispatchers.IO) {
            decodeAvatarSource(context, uri)?.let { AvatarSourceState.Ready(it) } ?: AvatarSourceState.Failed
        }
    }
    return state
}

@Composable
private fun CropLoading() {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        CircularProgressIndicator(color = Color.White)
        Text(stringResource(R.string.avatar_crop_loading), color = Color.White.copy(alpha = 0.72f))
    }
}

@Composable
private fun CropFailed() {
    Text(
        text = stringResource(R.string.avatar_crop_open_failed),
        color = Color.White,
        textAlign = TextAlign.Center,
        modifier = Modifier.padding(horizontal = Spacing.lg),
    )
}

@Composable
private fun AvatarCropViewport(
    bitmap: Bitmap,
    transform: AvatarCropTransform,
    enabled: Boolean,
    onTransformChange: (AvatarCropTransform) -> Unit,
) {
    val currentTransform by rememberUpdatedState(transform)
    val currentOnChange by rememberUpdatedState(onTransformChange)
    val image = remember(bitmap) { bitmap.asImageBitmap() }

    BoxWithConstraints(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        val side = minOf(maxWidth, maxHeight)
        Canvas(
            modifier = Modifier
                .size(side)
                .clipToBounds()
                .pointerInput(bitmap, enabled) {
                    if (!enabled) return@pointerInput
                    detectTransformGestures { centroid, pan, zoom, _ ->
                        val sidePx = size.width.toFloat()
                        currentOnChange(
                            currentTransform.gestured(
                                zoomFactor = zoom,
                                pan = CropPoint(pan.x / sidePx, pan.y / sidePx),
                                centroid = CropPoint(
                                    (centroid.x - sidePx / 2f) / sidePx,
                                    (centroid.y - sidePx / 2f) / sidePx,
                                ),
                                imageWidth = bitmap.width,
                                imageHeight = bitmap.height,
                            ),
                        )
                    }
                },
        ) {
            drawRect(Color.White)
            drawCropImage(image, transform)
            drawCropGuide(size.minDimension)
        }
    }
}

@Composable
private fun ZoomRow(
    zoom: Float,
    enabled: Boolean,
    onZoomChange: (Float) -> Unit,
) {
    val description = stringResource(R.string.avatar_crop_zoom)
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Icon(Icons.Filled.ZoomIn, contentDescription = null, tint = Color.White.copy(alpha = 0.8f))
        Slider(
            value = zoom,
            onValueChange = onZoomChange,
            valueRange = MIN_AVATAR_ZOOM..MAX_AVATAR_ZOOM,
            enabled = enabled,
            colors = SliderDefaults.colors(
                thumbColor = Color.White,
                activeTrackColor = Color.White,
                inactiveTrackColor = Color.White.copy(alpha = 0.3f),
            ),
            modifier = Modifier
                .weight(1f)
                .semantics { contentDescription = description },
        )
    }
}

@Composable
private fun CropActions(
    isUploading: Boolean,
    onReset: () -> Unit,
    onUsePhoto: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        TextButton(
            onClick = onReset,
            enabled = !isUploading,
            colors = ButtonDefaults.textButtonColors(contentColor = Color.White),
        ) {
            Text(stringResource(R.string.avatar_crop_reset))
        }
        Button(
            onClick = onUsePhoto,
            enabled = !isUploading,
            colors = ButtonDefaults.buttonColors(containerColor = Color.White, contentColor = Color.Black),
        ) {
            if (isUploading) {
                CircularProgressIndicator(
                    modifier = Modifier.size(18.dp),
                    color = Color.Black,
                    strokeWidth = 2.dp,
                )
                Spacer(Modifier.width(Spacing.sm))
                Text(stringResource(R.string.avatar_crop_uploading))
            } else {
                Text(stringResource(R.string.avatar_crop_use))
            }
        }
    }
}

private fun DrawScope.drawCropImage(image: ImageBitmap, transform: AvatarCropTransform) {
    val rect = avatarDrawRect(image.width, image.height, size.width, transform)
    val left = floor(rect.left).toInt()
    val top = floor(rect.top).toInt()
    val right = ceil(rect.left + rect.width).toInt()
    val bottom = ceil(rect.top + rect.height).toInt()

    clipRect {
        drawImage(
            image = image,
            dstOffset = IntOffset(left, top),
            dstSize = IntSize((right - left).coerceAtLeast(1), (bottom - top).coerceAtLeast(1)),
            filterQuality = FilterQuality.Medium,
        )
    }
}

private fun DrawScope.drawCropGuide(side: Float) {
    val center = Offset(size.width / 2f, size.height / 2f)
    val radius = side / 2f - GUIDE_INSET_DP.dp.toPx()
    val scrim = Path().apply {
        fillType = PathFillType.EvenOdd
        addRect(Rect(Offset.Zero, size))
        addOval(Rect(center = center, radius = radius))
    }

    drawPath(scrim, Color.Black.copy(alpha = SCRIM_ALPHA))
    drawCircle(Color.White, radius = radius, center = center, style = Stroke(width = 2.dp.toPx()))
}

internal fun cropAvatar(bitmap: Bitmap, transform: AvatarCropTransform): Bitmap {
    val output = Bitmap.createBitmap(AVATAR_EXPORT_SIZE, AVATAR_EXPORT_SIZE, Bitmap.Config.ARGB_8888)
    val canvas = AndroidCanvas(output)
    canvas.drawColor(AndroidColor.WHITE)
    val rect = avatarDrawRect(bitmap.width, bitmap.height, AVATAR_EXPORT_SIZE.toFloat(), transform)
    val paint = Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)
    canvas.drawBitmap(bitmap, null, RectF(rect.left, rect.top, rect.left + rect.width, rect.top + rect.height), paint)

    return output
}
