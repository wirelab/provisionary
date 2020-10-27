# Provisionary
Provisionary is a tool that quickly deploys S3 buckets following a set of pre-devined.

The current implementation contains the following steps:
1. Creates the buckets
2. Create a unique user for each bucket
3. Create a unique policy for the user to access that bucket
4. Create the access tokens to access the bucket programmatically. 

## Installation
Globally require the package by running `composer global require wirelab/provisionary`

Note: Ensure that composers system-wide vendor bin is in your `$path`.

## Provision buckets
run `provisionary s3 [name]`
Optionally, you can add a --clean flag which will delete the created resources if you're just testing


## License

Provisionary is open-sourced software licensed under the [MIT license](LICENSE.md).

# TODO
- Include Cloudfront set-up
- Make more generic  
