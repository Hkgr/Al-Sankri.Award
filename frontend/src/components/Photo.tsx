export type PhotoSource = {
  srcSet: string
  type?: string
  media?: string
  /** Intrinsic size of this source when it is an art-directed crop. */
  width?: number
  height?: number
}

type PhotoProps = {
  sources: PhotoSource[]
  fallback: string
  width: number
  height: number
  alt: string
  sizes: string
  className?: string
  loading?: 'eager' | 'lazy'
  fetchPriority?: 'high' | 'auto'
}

/** A responsive <picture>: WebP sets, optional art-directed crops, JPEG fallback. */
export function Photo({
  sources,
  fallback,
  width,
  height,
  alt,
  sizes,
  className,
  loading = 'lazy',
  fetchPriority = 'auto',
}: PhotoProps) {
  return (
    <picture className={className}>
      {sources.map((source) => (
        <source
          key={`${source.media ?? 'all'}-${source.type ?? 'fallback'}`}
          srcSet={source.srcSet}
          type={source.type}
          media={source.media}
          sizes={sizes}
          width={source.width}
          height={source.height}
        />
      ))}
      <img
        src={fallback}
        width={width}
        height={height}
        alt={alt}
        sizes={sizes}
        loading={loading}
        decoding="async"
        fetchPriority={fetchPriority}
      />
    </picture>
  )
}
