package fr.gshz.hideandseek.feature.settings.avatar

import kotlin.math.min

internal const val MIN_AVATAR_ZOOM = 1f
internal const val MAX_AVATAR_ZOOM = 4f

/** Offsets are viewport fractions so the preview and the exported bitmap share one transform. */
internal data class AvatarCropTransform(
    val zoom: Float = MIN_AVATAR_ZOOM,
    val offsetX: Float = 0f,
    val offsetY: Float = 0f,
)

internal data class AvatarDrawRect(
    val left: Float,
    val top: Float,
    val width: Float,
    val height: Float,
)

internal data class CropPoint(val x: Float, val y: Float)

internal fun AvatarCropTransform.clamped(imageWidth: Int, imageHeight: Int): AvatarCropTransform {
    val shortSide = min(imageWidth, imageHeight).coerceAtLeast(1).toFloat()
    val safeZoom = zoom.coerceIn(MIN_AVATAR_ZOOM, MAX_AVATAR_ZOOM)
    val maxX = ((imageWidth / shortSide * safeZoom - 1f) / 2f).coerceAtLeast(0f)
    val maxY = ((imageHeight / shortSide * safeZoom - 1f) / 2f).coerceAtLeast(0f)

    return copy(
        zoom = safeZoom,
        offsetX = offsetX.coerceIn(-maxX, maxX),
        offsetY = offsetY.coerceIn(-maxY, maxY),
    )
}

/**
 * Zooms around the pinch centroid so the image point under the fingers stays put, then applies
 * the drag. Offset and centroid are fractions of the viewport, relative to its center.
 */
internal fun AvatarCropTransform.gestured(
    zoomFactor: Float,
    pan: CropPoint,
    centroid: CropPoint,
    imageWidth: Int,
    imageHeight: Int,
): AvatarCropTransform {
    val nextZoom = (zoom * zoomFactor).coerceIn(MIN_AVATAR_ZOOM, MAX_AVATAR_ZOOM)
    val ratio = nextZoom / zoom

    return AvatarCropTransform(
        zoom = nextZoom,
        offsetX = centroid.x - (centroid.x - offsetX) * ratio + pan.x,
        offsetY = centroid.y - (centroid.y - offsetY) * ratio + pan.y,
    ).clamped(imageWidth, imageHeight)
}

internal fun avatarDrawRect(
    imageWidth: Int,
    imageHeight: Int,
    viewportSize: Float,
    transform: AvatarCropTransform,
): AvatarDrawRect {
    val safe = transform.clamped(imageWidth, imageHeight)
    val scale = viewportSize / min(imageWidth, imageHeight).coerceAtLeast(1)
    val width = imageWidth * scale * safe.zoom
    val height = imageHeight * scale * safe.zoom

    return AvatarDrawRect(
        left = (viewportSize - width) / 2f + safe.offsetX * viewportSize,
        top = (viewportSize - height) / 2f + safe.offsetY * viewportSize,
        width = width,
        height = height,
    )
}
